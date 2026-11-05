<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Models\Track;
use App\Support\ApiResponse;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio' => ['required', 'file', 'mimes:mp3', 'max:204800'],
            'title' => ['required', 'string', 'max:255'],
            'rights_confirmed' => ['required', 'accepted'],
        ]);
        $user = $request->user();
        $audioPath = $validated['audio']->storeAs(
            "tracks/uploads/{$user->id}",
            Str::uuid().'.mp3',
            'public',
        );
        abort_unless(is_string($audioPath), 500, 'Unable to store the uploaded song.');

        $track = Track::create([
            'uploaded_by' => $user->id,
            'title' => trim($validated['title']),
            'artist' => $user->name,
            'audio_path' => $audioPath,
            'source' => 'User submission',
            'license_name' => 'Distribution rights confirmed by uploader',
            'downloadable' => false,
        ]);

        return ApiResponse::success($track, 'tracks.created', 201);
    }

    public function myUploads(Request $request): JsonResponse
    {
        $tracks = Track::query()
            ->where('uploaded_by', $request->user()->id)
            ->latest()
            ->paginate(100);

        return ApiResponse::success(
            $tracks->items(),
            meta: [
                'current_page' => $tracks->currentPage(),
                'last_page' => $tracks->lastPage(),
                'total' => $tracks->total(),
            ],
        );
    }

    public function destroyUploaded(Request $request, Track $track): JsonResponse
    {
        abort_unless(
            $track->uploaded_by === $request->user()->id
                && str_starts_with((string) $track->audio_path, "tracks/uploads/{$request->user()->id}/"),
            404,
        );

        $disk = Storage::disk('public');

        if ($disk->exists($track->audio_path) && ! $disk->delete($track->audio_path)) {
            abort(500, 'Unable to remove the uploaded song file.');
        }

        $track->delete();

        return ApiResponse::success(null, 'tracks.deleted');
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Track::query()->where('is_active', true)->orderBy('title');

        if (isset($validated['q']) && $validated['q'] !== '') {
            $query->search($validated['q']);
        }

        $tracks = $query->paginate($validated['per_page'] ?? 20);

        return ApiResponse::success(
            $tracks->items(),
            meta: [
                'current_page' => $tracks->currentPage(),
                'last_page' => $tracks->lastPage(),
                'per_page' => $tracks->perPage(),
                'total' => $tracks->total(),
            ],
        );
    }

    public function show(Track $track): JsonResponse
    {
        abort_unless($track->is_active, 404);

        return ApiResponse::success($track);
    }

    public function downloads(Request $request): JsonResponse
    {
        $downloads = Download::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('track', fn ($query) => $query->where('is_active', true))
            ->with('track')
            ->latest('downloaded_at')
            ->paginate(20);

        return ApiResponse::success(
            $downloads->items(),
            meta: [
                'current_page' => $downloads->currentPage(),
                'last_page' => $downloads->lastPage(),
                'total' => $downloads->total(),
            ],
        );
    }

    public function download(Request $request, Track $track): StreamedResponse
    {
        abort_unless($track->downloadable, 403, 'This track is not available for download.');
        $disk = $this->availableTrackDisk($track);

        Download::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'track_id' => $track->id],
            ['downloaded_at' => now()],
        );

        return $disk->download($track->audio_path);
    }

    public function playUrl(Track $track): JsonResponse
    {
        $this->availableTrackDisk($track);
        $expiresAt = now()->addMinutes(10);

        return ApiResponse::success([
            'url' => URL::temporarySignedRoute(
                'tracks.play',
                $expiresAt,
                ['track' => $track->getKey()],
                absolute: false,
            ),
            'expires_at' => $expiresAt,
        ]);
    }

    public function play(Track $track): StreamedResponse
    {
        $disk = $this->availableTrackDisk($track);
        $path = (string) $track->audio_path;
        $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

        return $disk->response($path, headers: ['Content-Type' => $mimeType]);
    }

    private function availableTrackDisk(Track $track): Filesystem
    {
        abort_unless($track->is_active, 404);
        abort_if($track->audio_path === null, 404, 'The track audio file is not available.');

        $disk = Storage::disk('public');
        abort_unless($disk->exists($track->audio_path), 404, 'The track audio file is not available.');

        return $disk;
    }
}
