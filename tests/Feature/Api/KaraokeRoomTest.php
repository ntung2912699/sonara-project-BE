<?php

namespace Tests\Feature\Api;

use App\Models\KaraokeRoom;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KaraokeRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_can_create_join_and_schedule_a_duet_room(): void
    {
        $host = User::factory()->create(['name' => 'Host']);
        $guest = User::factory()->create(['name' => 'Guest']);

        Sanctum::actingAs($host);
        $created = $this->postJson('/api/karaoke/rooms', [
            'beat_type' => 'youtube',
            'beat_id' => 'abcdefghijk',
            'beat_title' => 'Duet beat',
            'beat_artist' => 'Channel',
        ])->assertCreated()
            ->assertJsonPath('data.beat.title', 'Duet beat')
            ->assertJsonPath('data.status', 'waiting');

        $code = $created->json('data.code');
        $this->postJson("/api/karaoke/rooms/{$code}/start")->assertStatus(409);

        Sanctum::actingAs($guest);
        $this->postJson("/api/karaoke/rooms/{$code}/join")
            ->assertOk()
            ->assertJsonPath('data.guest', 'Guest')
            ->assertJsonPath('data.is_host', false);
        $this->postJson("/api/karaoke/rooms/{$code}/signal", [
            'offer' => ['type' => 'offer', 'sdp' => 'host-offer'],
        ])->assertUnprocessable();

        Sanctum::actingAs($host);
        $this->postJson("/api/karaoke/rooms/{$code}/signal", [
            'offer' => ['type' => 'offer', 'sdp' => 'host-offer'],
        ])->assertOk();
        Sanctum::actingAs($guest);
        $this->getJson("/api/karaoke/rooms/{$code}")
            ->assertOk()
            ->assertJsonPath('data.webrtc_offer.sdp', 'host-offer');
        $this->postJson("/api/karaoke/rooms/{$code}/signal", [
            'answer' => ['type' => 'answer', 'sdp' => 'guest-answer'],
        ])->assertOk();

        Sanctum::actingAs($host);
        $this->postJson("/api/karaoke/rooms/{$code}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.is_host', true);

        Sanctum::actingAs($guest);
        $this->postJson("/api/karaoke/rooms/{$code}/join")->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
        $this->getJson("/api/karaoke/rooms/{$code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.host', 'Host');
    }

    public function test_room_rejects_non_participants_and_a_third_guest(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $other = User::factory()->create();
        $room = KaraokeRoom::query()->create([
            'code' => 'ABC123',
            'created_by' => $host->id,
            'joined_by' => $guest->id,
            'beat_type' => 'youtube',
            'beat_id' => 'abcdefghijk',
            'beat_title' => 'Beat',
            'status' => 'waiting',
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/karaoke/rooms/{$room->code}")->assertForbidden();
        $this->postJson("/api/karaoke/rooms/{$room->code}/join")->assertStatus(409);
        $this->postJson("/api/karaoke/rooms/{$room->code}/leave")->assertForbidden();

        Sanctum::actingAs($guest);
        $this->postJson("/api/karaoke/rooms/{$room->code}/leave")->assertOk();
        Sanctum::actingAs($other);
        $this->postJson("/api/karaoke/rooms/{$room->code}/join")->assertOk();

        Sanctum::actingAs($host);
        $this->postJson("/api/karaoke/rooms/{$room->code}/leave")->assertOk();
        $this->getJson("/api/karaoke/rooms/{$room->code}")->assertNotFound();
    }

    public function test_catalog_room_requires_a_playable_catalog_track(): void
    {
        $user = User::factory()->create();
        Track::query()->create(['title' => 'Silent track', 'artist' => 'Artist', 'audio_path' => null]);
        Sanctum::actingAs($user);

        $this->postJson('/api/karaoke/rooms', [
            'beat_type' => 'catalog',
            'beat_id' => '1',
            'beat_title' => 'Silent track',
        ])->assertUnprocessable();
    }

    public function test_room_creation_requires_authentication_and_valid_video_ids(): void
    {
        $this->postJson('/api/karaoke/rooms', [])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/karaoke/rooms', [
            'beat_type' => 'youtube',
            'beat_id' => 'invalid!',
            'beat_title' => 'Bad video',
        ])->assertUnprocessable()
            ->assertJsonPath('message.code', 'validation.failed');
    }
}
