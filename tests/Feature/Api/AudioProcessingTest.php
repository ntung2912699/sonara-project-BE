<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessAudioFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AudioProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rights_attested_lossless_upload_is_queued(): void
    {
        Queue::fake();
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/audio-processing', [
            'audio' => UploadedFile::fake()->create('master.wav', 100, 'audio/wav'),
            'rights_confirmed' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ProcessAudioFile::class);
    }

    public function test_audio_upload_requires_rights_confirmation(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/audio-processing', [
            'audio' => UploadedFile::fake()->create('master.wav', 100, 'audio/wav'),
        ])->assertUnprocessable()
            ->assertJsonPath('message.code', 'validation.failed');

        Queue::assertNothingPushed();
    }
}
