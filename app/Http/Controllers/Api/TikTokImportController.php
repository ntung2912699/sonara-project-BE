<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TikTokImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\ApiResponse;

class TikTokImportController extends Controller
{
    private const ALLOWED_HOSTS = [
        'tiktok.com',
        'www.tiktok.com',
        'm.tiktok.com',
        'vm.tiktok.com',
        'vt.tiktok.com',
    ];

    public function index(Request $request): JsonResponse
    {
        $imports = $request->user()->tiktokImportRequests()
            ->with('track')
            ->latest()
            ->paginate(20);

        return ApiResponse::success($imports->items(), meta: [
            'current_page' => $imports->currentPage(),
            'last_page' => $imports->lastPage(),
            'per_page' => $imports->perPage(),
            'total' => $imports->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'video_url' => ['required', 'url', 'max:2048'],
            'rights_confirmed' => ['required', 'accepted'],
        ]);

        $host = strtolower((string) parse_url($validated['video_url'], PHP_URL_HOST));
        abort_unless(in_array($host, self::ALLOWED_HOSTS, true), 422, 'Only TikTok video links are accepted.');
        abort_unless(strtolower((string) parse_url($validated['video_url'], PHP_URL_SCHEME)) === 'https', 422, 'The video link must use HTTPS.');

        $importRequest = $request->user()->tiktokImportRequests()->create([
            'video_url' => $validated['video_url'],
            'rights_attested_at' => now(),
            'status' => 'review_required',
        ]);

        return ApiResponse::success($importRequest, 'tiktok.review_required', 202);
    }
}
