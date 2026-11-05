<?php

namespace Tests\Feature\Api;

use App\Models\SocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_facebook_token_is_verified_before_sign_in(): void
    {
        config([
            'services.facebook.app_id' => 'facebook-app',
            'services.facebook.app_secret' => 'test-secret',
            'services.facebook.graph_version' => 'v23.0',
        ]);
        Http::fake([
            'graph.facebook.com/v23.0/debug_token*' => Http::response([
                'data' => ['is_valid' => true, 'app_id' => 'facebook-app', 'user_id' => 'fb-123'],
            ]),
            'graph.facebook.com/v23.0/me*' => Http::response([
                'id' => 'fb-123',
                'name' => 'Facebook Listener',
                'email' => 'facebook@example.com',
            ]),
        ]);

        $this->postJson('/api/auth/facebook', ['access_token' => 'provider-access-token'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Facebook Listener')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('social_identities', [
            'provider' => 'facebook',
            'provider_user_id' => 'fb-123',
        ]);
    }

    public function test_invalid_facebook_token_does_not_create_an_account(): void
    {
        config([
            'services.facebook.app_id' => 'facebook-app',
            'services.facebook.app_secret' => 'test-secret',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => ['is_valid' => false]])]);

        $this->postJson('/api/auth/facebook', ['access_token' => 'invalid-token'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_tiktok_identity_is_verified_and_linked(): void
    {
        config(['services.tiktok.client_key' => 'tiktok-client']);
        Http::fake([
            'open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => ['user' => ['open_id' => 'tt-123', 'display_name' => 'TikTok Listener']],
            ]),
        ]);

        $this->postJson('/api/auth/tiktok', ['access_token' => 'provider-access-token'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'TikTok Listener')
            ->assertJsonPath('data.user.email', null);

        $this->assertDatabaseHas('social_identities', [
            'provider' => 'tiktok',
            'provider_user_id' => 'tt-123',
        ]);
    }
}
