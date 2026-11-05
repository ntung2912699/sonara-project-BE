<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SongReport;
use App\Models\Track;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SongReportController extends Controller
{
    public function store(Request $request, Track $track): JsonResponse
    {
        abort_unless($track->is_active, 404);

        $validated = $request->validate([
            'reason' => ['required', 'in:copyright,inappropriate,duplicate,other'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $alreadyReported = SongReport::query()
            ->where('track_id', $track->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyReported) {
            return ApiResponse::error('reports.already_submitted', 409);
        }

        $report = SongReport::query()->create([
            'track_id' => $track->id,
            'user_id' => $request->user()->id,
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
        ]);

        return ApiResponse::success($report, 'reports.submitted', 201);
    }
}
