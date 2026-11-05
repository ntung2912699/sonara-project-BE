<?php

namespace Tests\Feature\Api;

use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MusicLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_can_be_searched(): void
    {
        Track::create(['title' => 'Blue Sky', 'artist' => 'The Example']);
        Track::create(['title' => 'Quiet Night', 'artist' => 'Other Artist']);

        $this->getJson('/api/tracks?q=Blue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Blue Sky');
    }

    public function test_user_can_download_a_rights_cleared_track(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tracks/blue-sky.mp3', 'audio');
        $track = Track::create([
            'title' => 'Blue Sky',
            'artist' => 'The Example',
            'audio_path' => 'tracks/blue-sky.mp3',
            'downloadable' => true,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->get('/api/tracks/'.$track->id.'/download')->assertOk();
        $this->assertDatabaseHas('downloads', ['track_id' => $track->id]);
    }

    public function test_user_cannot_download_a_track_not_marked_downloadable(): void
    {
        $track = Track::create(['title' => 'Preview', 'artist' => 'The Example']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/tracks/'.$track->id.'/download')->assertForbidden();
    }

    public function test_user_can_play_a_rights_cleared_track_with_a_temporary_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tracks/blue-sky.mp3', 'audio');
        $track = Track::create([
            'title' => 'Blue Sky',
            'artist' => 'The Example',
            'audio_path' => 'tracks/blue-sky.mp3',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $playUrl = $this->getJson('/api/tracks/'.$track->id.'/play-url')
            ->assertOk()
            ->json('data.url');

        $this->get($playUrl)
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=blue-sky.mp3');
    }

    public function test_track_playback_is_not_available_without_an_audio_file(): void
    {
        $track = Track::create(['title' => 'Unavailable', 'artist' => 'The Example']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/tracks/'.$track->id.'/play-url')->assertNotFound();
    }

    public function test_playlist_is_private_to_its_owner(): void
    {
        $playlist = Playlist::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Private Mix',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/playlists/'.$playlist->id)->assertNotFound();
    }
}
