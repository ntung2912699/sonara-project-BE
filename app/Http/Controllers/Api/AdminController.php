<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SongReport;
use App\Models\Track;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = User::query()->orderBy('name');

        if (($validated['q'] ?? '') !== '') {
            $query->where(function ($query) use ($validated): void {
                $query->where('name', 'like', '%'.$validated['q'].'%')
                    ->orWhere('email', 'like', '%'.$validated['q'].'%');
            });
        }

        return $this->paginated($query->paginate($validated['per_page'] ?? 25));
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $validated['is_active'];

        if (! $isActive && $user->is($request->user())) {
            return ApiResponse::error('admin.cannot_lock_self', 422);
        }

        DB::transaction(function () use ($user, $isActive): void {
            $user->forceFill(['is_active' => $isActive])->save();

            if (! $isActive) {
                $user->tokens()->delete();
            }
        });

        return ApiResponse::success($user->refresh(), 'admin.user_updated');
    }

    public function tracks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Track::query()->with('uploader:id,name,email')->orderByDesc('created_at');

        if (($validated['q'] ?? '') !== '') {
            $query->where(function ($query) use ($validated): void {
                $term = '%'.$validated['q'].'%';
                $query->where('title', 'like', $term)
                    ->orWhere('artist', 'like', $term)
                    ->orWhereHas('uploader', function ($uploader) use ($term): void {
                        $uploader->where('name', 'like', $term)->orWhere('email', 'like', $term);
                    });
            });
        }

        return $this->paginated($query->paginate($validated['per_page'] ?? 25));
    }

    public function updateTrack(Request $request, Track $track): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'artist' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'downloadable' => ['sometimes', 'required', 'boolean'],
        ]);
        $track->update($validated);

        return ApiResponse::success($track->refresh(), 'admin.track_updated');
    }

    public function destroyTrack(Track $track): JsonResponse
    {
        if ($track->audio_path !== null) {
            $disk = Storage::disk('public');

            if ($disk->exists($track->audio_path) && ! $disk->delete($track->audio_path)) {
                abort(500, 'Unable to remove the song file.');
            }
        }

        $track->delete();

        return ApiResponse::success(null, 'admin.track_deleted');
    }

    public function reports(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:pending,resolved,dismissed'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SongReport::query()
            ->with([
                'track:id,title,artist',
                'reporter:id,name,email',
                'reviewer:id,name',
            ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest();

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return $this->paginated($query->paginate($validated['per_page'] ?? 25));
    }

    public function updateReport(Request $request, SongReport $songReport): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,resolved,dismissed'],
        ]);
        $status = $validated['status'];
        $songReport->update([
            'status' => $status,
            'reviewed_by' => $status === 'pending' ? null : $request->user()->id,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ]);

        return ApiResponse::success(
            $songReport->load(['track:id,title,artist', 'reporter:id,name,email', 'reviewer:id,name']),
            'admin.report_updated',
        );
    }

    private function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return ApiResponse::success(
            $paginator->items(),
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        );
    }
}
