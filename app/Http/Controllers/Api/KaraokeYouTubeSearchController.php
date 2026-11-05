<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KaraokeYouTubeSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $apiKey = config('services.youtube.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            return ApiResponse::error('youtube.search_unavailable', 503);
        }

        $query = trim($validated['q']);
        $cacheKey = 'youtube-karaoke-search:'.hash('sha256', mb_strtolower($query));

        try {
            $videos = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($apiKey, $query): array {
                $response = Http::timeout(8)->get('https://www.googleapis.com/youtube/v3/search', [
                    'key' => $apiKey,
                    'part' => 'snippet',
                    'type' => 'video',
                    'videoEmbeddable' => 'true',
                    'safeSearch' => 'moderate',
                    'maxResults' => 8,
                    'q' => $query,
                ])->throw();

                return collect($response->json('items', []))
                    ->map(fn (array $item): array => [
                        'video_id' => data_get($item, 'id.videoId'),
                        'title' => data_get($item, 'snippet.title'),
                        'channel' => data_get($item, 'snippet.channelTitle'),
                        'thumbnail' => data_get($item, 'snippet.thumbnails.medium.url')
                            ?? data_get($item, 'snippet.thumbnails.default.url'),
                    ])
                    ->filter(fn (array $video): bool => is_string($video['video_id']) && $video['video_id'] !== '')
                    ->values()
                    ->all();
            });
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('YouTube beat search failed.', [
                'exception' => $exception::class,
                'query_hash' => hash('sha256', $query),
            ]);

            return ApiResponse::error('youtube.search_failed', 502);
        }

        return ApiResponse::success($videos, 'youtube.search_succeeded');
    }
}
