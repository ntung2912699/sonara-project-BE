<?php

namespace Tests\Feature\Api;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_submit_a_rights_confirmed_song(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['name' => 'Song Creator']);
        Sanctum::actingAs($user);

        $this->postJson('/api/tracks', [
            'audio' => UploadedFile::fake()->createWithContent('original-song.mp3', "\xFF\xFB\x90\x64".str_repeat("\0", 128)),
            'title' => 'My renamed song',
            'rights_confirmed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'My renamed song')
            ->assertJsonPath('data.artist', 'Song Creator')
            ->assertJsonPath('data.uploaded_by', $user->id)
            ->assertJsonPath('data.downloadable', false);

        $track = Track::query()->sole();
        $this->assertSame('User submission', $track->source);
        Storage::disk('public')->assertExists($track->audio_path);
    }

    public function test_song_submission_requires_distribution_rights_confirmation(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tracks', [
            'audio' => UploadedFile::fake()->createWithContent('song.mp3', "\xFF\xFB\x90\x64"),
            'title' => 'Song',
        ])->assertUnprocessable()
            ->assertJsonPath('message.code', 'validation.failed');

        $this->assertDatabaseCount('tracks', 0);
    }

    public function test_user_can_remove_their_uploaded_song(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $audioPath = "tracks/uploads/{$user->id}/song.mp3";
        Storage::disk('public')->put($audioPath, 'audio');
        $track = Track::create([
            'uploaded_by' => $user->id,
            'title' => 'My song',
            'artist' => $user->name,
            'audio_path' => $audioPath,
            'source' => 'User submission',
        ]);

        $this->deleteJson("/api/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('tracks', ['id' => $track->id]);
        Storage::disk('public')->assertMissing($audioPath);
    }

    public function test_user_cannot_remove_another_users_song_or_catalog_track(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $track = Track::create([
            'uploaded_by' => $otherUser->id,
            'title' => 'Another users song',
            'artist' => $otherUser->name,
            'audio_path' => "tracks/uploads/{$otherUser->id}/song.mp3",
        ]);
        $catalogTrack = Track::create(['title' => 'Catalog track', 'artist' => 'Artist']);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/tracks/{$track->id}")->assertNotFound();
        $this->deleteJson("/api/tracks/{$catalogTrack->id}")->assertNotFound();

        $this->assertDatabaseHas('tracks', ['id' => $track->id]);
        $this->assertDatabaseHas('tracks', ['id' => $catalogTrack->id]);
    }
}
