<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KaraokeYouTubeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_search_embeddable_youtube_videos(): void
    {
        Config::set('services.youtube.api_key', 'test-key');
        Http::fake([
            'www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'abc123'],
                    'snippet' => [
                        'title' => 'Karaoke beat',
                        'channelTitle' => 'Sonora test',
                        'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/abc123/mqdefault.jpg']],
                    ],
                ]],
            ]),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/karaoke/youtube-search?q=karaoke+beat')
            ->assertOk()
            ->assertJsonPath('data.0.video_id', 'abc123')
            ->assertJsonPath('data.0.title', 'Karaoke beat')
            ->assertJsonPath('data.0.channel', 'Sonora test');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/search')
            && $request['videoEmbeddable'] === 'true'
            && $request['type'] === 'video'
            && $request['key'] === 'test-key');
    }

    public function test_youtube_search_is_unavailable_without_an_api_key(): void
    {
        Config::set('services.youtube.api_key', null);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/karaoke/youtube-search?q=karaoke+beat')
            ->assertStatus(503)
            ->assertJsonPath('message.code', 'youtube.search_unavailable');
    }

    public function test_youtube_search_reports_upstream_errors(): void
    {
        Config::set('services.youtube.api_key', 'test-key');
        Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response([], 403)]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/karaoke/youtube-search?q=karaoke+beat')
            ->assertStatus(502)
            ->assertJsonPath('message.code', 'youtube.search_failed');
    }

    public function test_youtube_search_requires_authentication_and_a_valid_query(): void
    {
        $this->getJson('/api/karaoke/youtube-search?q=karaoke')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/karaoke/youtube-search?q=x')
            ->assertUnprocessable()
            ->assertJsonPath('message.code', 'validation.failed');
    }
}
