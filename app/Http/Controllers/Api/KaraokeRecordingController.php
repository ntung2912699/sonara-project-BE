<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KaraokeRecordingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio' => ['required', 'file', 'mimes:webm,ogg,mp4,m4a,mp3,wav', 'max:204800'],
            'title' => ['required', 'string', 'max:255'],
            'rights_confirmed' => ['required', 'accepted'],
        ]);
        $extension = strtolower($validated['audio']->getClientOriginalExtension());
        abort_unless(in_array($extension, ['webm', 'ogg', 'mp4', 'm4a', 'mp3', 'wav'], true), 422);

        $user = $request->user();
        $audioPath = $validated['audio']->storeAs(
            "tracks/karaoke/{$user->id}",
            Str::uuid().'.'.$extension,
            'public',
        );
        abort_unless(is_string($audioPath), 500, 'Unable to store the karaoke recording.');

        $track = Track::create([
            'uploaded_by' => $user->id,
            'title' => trim($validated['title']),
            'artist' => $user->name,
            'audio_path' => $audioPath,
            'source' => 'Karaoke recording',
            'license_name' => 'Distribution rights confirmed by uploader',
            'downloadable' => false,
        ]);

        return ApiResponse::success($track, 'tracks.created', 201);
    }
}
