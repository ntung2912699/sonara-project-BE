<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\RegistrationOtpDelivery;
use Database\Seeders\ApiMessageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AuthAndProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receive_a_token(): void
    {
        $this->seed(ApiMessageSeeder::class);
        Notification::fake();
        $this->postJson('/api/register', [
            'name' => 'Test Listener',
            'channel' => 'email',
            'email' => 'listener@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message.code', 'auth.registration_otp_sent')
            ->assertJsonStructure(['data' => ['challenge_id', 'channel', 'destination', 'expires_at']]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_returns_service_unavailable_when_otp_delivery_fails(): void
    {
        $this->seed(ApiMessageSeeder::class);
        $this->mock(RegistrationOtpDelivery::class)
            ->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('SMTP connection failed.'));

        $this->postJson('/api/register', [
            'name' => 'Test Listener',
            'channel' => 'email',
            'email' => 'listener@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])
            ->assertServiceUnavailable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message.code', 'service.unavailable');

        $this->assertDatabaseCount('registration_otps', 0);
    }

    public function test_a_user_can_update_their_profile(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->is_active);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'New Name',
                'bio' => 'Music fan',
                'favorite_items' => ['jazz', 'ambient'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.favorite_items.0', 'jazz');
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_locked_user_cannot_sign_in(): void
    {
        $user = User::factory()->create([
            'email' => 'locked@example.com',
            'password' => Hash::make('StrongPassword123!'),
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'StrongPassword123!',
        ])
            ->assertForbidden()
            ->assertJsonPath('message.code', 'auth.account_locked');
    }

    public function test_user_can_upload_a_profile_photo_with_profile_changes(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->post('/api/profile', [
            '_method' => 'PUT',
            'name' => 'Photo Listener',
            'bio' => 'Enjoys new music.',
            'favorite_items' => ['Jazz'],
            'avatar' => UploadedFile::fake()->createWithContent(
                'avatar.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jqioAAAAASUVORK5CYII='),
            ),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Photo Listener')
            ->assertJsonPath('data.bio', 'Enjoys new music.')
            ->assertJsonPath('data.avatar_url', fn (string $url): bool => str_contains($url, '/storage/avatars/'));

        $this->assertSame('Photo Listener', $user->fresh()->name);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);
    }
}
