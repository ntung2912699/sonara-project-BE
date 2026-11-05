<?php

namespace Tests\Feature\Api;

use App\Models\SongReport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SongReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_report_a_song_for_admin_review(): void
    {
        $user = User::factory()->create();
        $track = Track::create(['title' => 'Reported song', 'artist' => 'Artist']);
        Sanctum::actingAs($user);

        $this->postJson("/api/tracks/{$track->id}/reports", [
            'reason' => 'copyright',
            'details' => 'I believe this song is being distributed without permission.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.user_id', $user->id);

        $this->assertDatabaseCount('song_reports', 1);
    }

    public function test_user_cannot_create_duplicate_pending_reports_for_a_song(): void
    {
        $user = User::factory()->create();
        $track = Track::create(['title' => 'Reported song', 'artist' => 'Artist']);
        SongReport::create([
            'track_id' => $track->id,
            'user_id' => $user->id,
            'reason' => 'other',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/tracks/{$track->id}/reports", ['reason' => 'other'])
            ->assertStatus(409);
    }

    public function test_user_cannot_report_an_inactive_song(): void
    {
        $user = User::factory()->create();
        $track = Track::create(['title' => 'Inactive song', 'artist' => 'Artist', 'is_active' => false]);
        Sanctum::actingAs($user);

        $this->postJson("/api/tracks/{$track->id}/reports", ['reason' => 'other'])->assertNotFound();
        $this->assertDatabaseCount('song_reports', 0);
    }
}
