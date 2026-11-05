<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = $request->user()->favoriteTracks()
            ->where('tracks.is_active', true)
            ->orderBy('title')
            ->paginate(20);

        return ApiResponse::success($favorites->items(), meta: [
            'current_page' => $favorites->currentPage(),
            'last_page' => $favorites->lastPage(),
            'per_page' => $favorites->perPage(),
            'total' => $favorites->total(),
        ]);
    }

    public function store(Request $request, Track $track): JsonResponse
    {
        abort_unless($track->is_active, 404);
        $request->user()->favoriteTracks()->syncWithoutDetaching([$track->id]);

        return ApiResponse::success($track, 'favorite.saved');
    }

    public function destroy(Request $request, Track $track): JsonResponse
    {
        $request->user()->favoriteTracks()->detach($track->id);

        return ApiResponse::success(null, 'favorite.removed');
    }
}
