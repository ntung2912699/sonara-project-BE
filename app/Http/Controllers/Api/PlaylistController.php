<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Track;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $playlists = Playlist::query()
            ->where('user_id', $request->user()->id)
            ->with(['tracks' => fn ($query) => $query->where('tracks.is_active', true)])
            ->latest()
            ->paginate(20);

        return ApiResponse::success($playlists->items(), meta: [
            'current_page' => $playlists->currentPage(),
            'last_page' => $playlists->lastPage(),
            'per_page' => $playlists->perPage(),
            'total' => $playlists->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        $playlist = $request->user()->playlists()->create($validated);

        return ApiResponse::success($playlist, 'playlist.created', 201);
    }

    public function show(Request $request, Playlist $playlist): JsonResponse
    {
        $this->ensureOwner($request, $playlist);

        return ApiResponse::success($playlist->load(['tracks' => fn ($query) => $query->where('tracks.is_active', true)]));
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->ensureOwner($request, $playlist);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        $playlist->update($validated);

        return ApiResponse::success($playlist->refresh(), 'playlist.updated');
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->ensureOwner($request, $playlist);
        $playlist->delete();

        return ApiResponse::success(null, 'playlist.deleted');
    }

    public function addTrack(Request $request, Playlist $playlist, Track $track): JsonResponse
    {
        $this->ensureOwner($request, $playlist);
        abort_unless($track->is_active, 404);

        if (! $playlist->tracks()->whereKey($track->id)->exists()) {
            $position = (int) ($playlist->tracks()->max('position') ?? -1) + 1;
            $playlist->tracks()->attach($track->id, ['position' => $position]);
        }

        return ApiResponse::success($playlist->load(['tracks' => fn ($query) => $query->where('tracks.is_active', true)]), 'playlist.track_added');
    }

    public function removeTrack(Request $request, Playlist $playlist, Track $track): JsonResponse
    {
        $this->ensureOwner($request, $playlist);
        $playlist->tracks()->detach($track->id);

        return ApiResponse::success(null, 'playlist.track_removed');
    }

    private function ensureOwner(Request $request, Playlist $playlist): void
    {
        abort_unless($playlist->user_id === $request->user()->id, 404);
    }
}
