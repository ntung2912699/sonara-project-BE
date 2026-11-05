<?php

namespace Tests\Feature\Api;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KaraokeRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_publish_a_rights_confirmed_karaoke_recording(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['name' => 'Karaoke Singer']);
        Sanctum::actingAs($user);

        $this->postJson('/api/karaoke/recordings', [
            'audio' => UploadedFile::fake()->createWithContent('my-cover.mp3', "\xFF\xFB\x90\x64".str_repeat("\0", 128)),
            'title' => 'My karaoke cover',
            'rights_confirmed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'My karaoke cover')
            ->assertJsonPath('data.artist', 'Karaoke Singer')
            ->assertJsonPath('data.source', 'Karaoke recording')
            ->assertJsonPath('data.downloadable', false);

        $track = Track::query()->sole();
        $this->assertSame($user->id, $track->uploaded_by);
        $this->assertStringStartsWith("tracks/karaoke/{$user->id}/", $track->audio_path);
        Storage::disk('public')->assertExists($track->audio_path);
    }

    public function test_karaoke_recording_requires_distribution_rights_confirmation(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/karaoke/recordings', [
            'audio' => UploadedFile::fake()->createWithContent('my-cover.mp3', "\xFF\xFB\x90\x64".str_repeat("\0", 128)),
            'title' => 'My karaoke cover',
        ])->assertUnprocessable()
            ->assertJsonPath('message.code', 'validation.failed');

        $this->assertDatabaseCount('tracks', 0);
    }

    public function test_karaoke_recording_requires_authentication(): void
    {
        $this->postJson('/api/karaoke/recordings', [])->assertUnauthorized();
    }
}
