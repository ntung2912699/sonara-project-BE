<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAudioFile;
use App\Models\AudioProcessing;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioProcessingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio' => ['required', 'file', 'mimes:wav,flac,aiff,aif', 'max:204800'],
            'rights_confirmed' => ['required', 'accepted'],
        ]);
        $extension = strtolower($validated['audio']->getClientOriginalExtension());
        $sourcePath = $validated['audio']->storeAs(
            'audio-sources/'.$request->user()->id,
            Str::uuid().'.'.$extension,
            'local',
        );
        abort_unless(is_string($sourcePath), 500, 'Unable to store the uploaded audio.');

        $processing = $request->user()->audioProcessings()->create([
            'source_path' => $sourcePath,
            'status' => 'queued',
        ]);
        ProcessAudioFile::dispatch($processing->id);

        return ApiResponse::success($processing, 'audio.queued', 202);
    }

    public function show(Request $request, AudioProcessing $audioProcessing): JsonResponse
    {
        $this->ensureOwner($request, $audioProcessing);

        return ApiResponse::success($audioProcessing);
    }

    public function download(
        Request $request,
        AudioProcessing $audioProcessing,
        string $format,
    ): StreamedResponse {
        $this->ensureOwner($request, $audioProcessing);
        abort_unless(in_array($format, ['master', 'aac', 'mp3'], true), 404);
        abort_unless($audioProcessing->status === 'complete', 409, 'Audio processing has not completed.');

        $path = match ($format) {
            'master' => $audioProcessing->source_path,
            'aac' => $audioProcessing->aac_path,
            'mp3' => $audioProcessing->mp3_path,
        };
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404, 'Audio output is unavailable.');

        return Storage::disk('local')->download($path);
    }

    private function ensureOwner(Request $request, AudioProcessing $audioProcessing): void
    {
        abort_unless($audioProcessing->user_id === $request->user()->id, 404);
    }
}
