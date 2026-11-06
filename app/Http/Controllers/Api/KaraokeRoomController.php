<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KaraokeRoom;
use App\Models\Track;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KaraokeRoomController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'beat_type' => ['required', 'in:catalog,youtube'],
            'beat_id' => ['required', 'string', 'max:80'],
            'beat_title' => ['required', 'string', 'max:255'],
            'beat_artist' => ['nullable', 'string', 'max:255'],
        ]);

        if (
            ($validated['beat_type'] === 'youtube' && ! preg_match('/^[A-Za-z0-9_-]{11}$/', $validated['beat_id']))
            || ($validated['beat_type'] === 'catalog' && ! ctype_digit($validated['beat_id']))
        ) {
            return ApiResponse::error('validation.failed', 422, [
                'beat_id' => ['The selected YouTube video ID is invalid.'],
            ]);
        }

        if ($validated['beat_type'] === 'catalog') {
            $track = Track::query()
                ->whereKey($validated['beat_id'])
                ->whereNotNull('audio_path')
                ->first();

            if (! $track) {
                return ApiResponse::error('karaoke.room_unavailable', 422);
            }

            $validated['beat_id'] = (string) $track->id;
            $validated['beat_title'] = $track->title;
            $validated['beat_artist'] = $track->artist;
        }

        do {
            $code = Str::upper(Str::random(6));
        } while (KaraokeRoom::query()->where('code', $code)->exists());

        $room = KaraokeRoom::query()->create([
            ...$validated,
            'code' => $code,
            'created_by' => $request->user()->id,
            'expires_at' => now()->addHours(2),
            'status' => 'waiting',
        ]);

        return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_created', 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $room = $this->findRoom($code);
        if ($room instanceof JsonResponse) {
            return $room;
        }

        if (! $this->isParticipant($room, $request)) {
            return ApiResponse::error('karaoke.room_forbidden', 403);
        }

        return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_found');
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $room = $this->findRoom($code);
        if ($room instanceof JsonResponse) {
            return $room;
        }

        if ((int) $room->created_by === (int) $request->user()->id) {
            return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_joined');
        }

        if ((int) $room->joined_by === (int) $request->user()->id) {
            return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_joined');
        }

        if ($room->joined_by !== null && (int) $room->joined_by !== (int) $request->user()->id) {
            return ApiResponse::error('karaoke.room_full', 409);
        }

        if ($room->status !== 'waiting') {
            return ApiResponse::error('karaoke.room_already_started', 409);
        }

        $room->joined_by = $request->user()->id;
        $room->save();

        return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_joined');
    }

    public function start(Request $request, string $code): JsonResponse
    {
        $room = $this->findRoom($code);
        if ($room instanceof JsonResponse) {
            return $room;
        }

        if ((int) $room->created_by !== (int) $request->user()->id) {
            return ApiResponse::error('karaoke.room_host_only', 403);
        }

        if ($room->status !== 'waiting') {
            return ApiResponse::error('karaoke.room_already_started', 409);
        }

        if ($room->joined_by === null) {
            return ApiResponse::error('karaoke.room_waiting_for_guest', 409);
        }

        $room->status = 'scheduled';
        $room->starts_at = now()->addSeconds(5);
        $room->save();

        return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_start_scheduled');
    }

    public function signal(Request $request, string $code): JsonResponse
    {
        $room = $this->findRoom($code);
        if ($room instanceof JsonResponse) {
            return $room;
        }

        if (! $this->isParticipant($room, $request)) {
            return ApiResponse::error('karaoke.room_forbidden', 403);
        }

        if ($room->status !== 'waiting') {
            return ApiResponse::error('karaoke.room_already_started', 409);
        }

        $isHost = (int) $room->created_by === (int) $request->user()->id;
        $field = $isHost ? 'offer' : 'answer';
        $sessionType = $isHost ? 'offer' : 'answer';
        $validated = $request->validate([
            $field => ['required', 'array'],
            "{$field}.type" => ['required', 'in:'.$sessionType],
            "{$field}.sdp" => ['required', 'string', 'max:20000'],
        ]);

        if ($isHost) {
            $room->webrtc_offer = $validated['offer'];
        } else {
            $room->webrtc_answer = $validated['answer'];
        }
        $room->save();

        return ApiResponse::success($this->roomData($room->load('creator:id,name', 'guest:id,name'), $request), 'karaoke.room_signal_saved');
    }

    public function leave(Request $request, string $code): JsonResponse
    {
        $room = $this->findRoom($code);
        if ($room instanceof JsonResponse) {
            return $room;
        }

        if ((int) $room->created_by === (int) $request->user()->id) {
            $room->status = 'completed';
            $room->webrtc_offer = null;
            $room->webrtc_answer = null;
        } elseif ((int) $room->joined_by === (int) $request->user()->id) {
            $room->joined_by = null;
            $room->status = 'waiting';
            $room->starts_at = null;
            $room->webrtc_offer = null;
            $room->webrtc_answer = null;
        } else {
            return ApiResponse::error('karaoke.room_forbidden', 403);
        }

        $room->save();

        return ApiResponse::success(null, 'karaoke.room_left');
    }

    private function findRoom(string $code): KaraokeRoom|JsonResponse
    {
        $room = KaraokeRoom::query()->where('code', Str::upper($code))->first();
        if (! $room || $room->expires_at->isPast() || $room->status === 'completed') {
            return ApiResponse::error('karaoke.room_not_found', 404);
        }

        return $room;
    }

    private function isParticipant(KaraokeRoom $room, Request $request): bool
    {
        $userId = (int) $request->user()->id;

        return (int) $room->created_by === $userId || (int) $room->joined_by === $userId;
    }

    private function roomData(KaraokeRoom $room, Request $request): array
    {
        return [
            'code' => $room->code,
            'status' => $room->status,
            'beat' => [
                'type' => $room->beat_type,
                'id' => $room->beat_id,
                'title' => $room->beat_title,
                'artist' => $room->beat_artist,
            ],
            'host' => $room->creator?->name,
            'guest' => $room->guest?->name,
            'is_host' => (int) $room->created_by === (int) $request->user()->id,
            'starts_at' => $room->starts_at?->toIso8601String(),
            'expires_at' => $room->expires_at->toIso8601String(),
            'webrtc_offer' => $room->webrtc_offer,
            'webrtc_answer' => $room->webrtc_answer,
        ];
    }
}
