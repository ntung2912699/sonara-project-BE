<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tiktok_url_is_queued_for_rights_review(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tiktok-imports', [
            'video_url' => 'https://www.tiktok.com/@creator/video/123',
            'rights_confirmed' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'review_required')
            ->assertJsonPath('message.code', 'tiktok.review_required');
    }

    public function test_non_tiktok_urls_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tiktok-imports', [
            'video_url' => 'https://example.com/video/123',
            'rights_confirmed' => true,
        ])->assertUnprocessable();
    }
}
