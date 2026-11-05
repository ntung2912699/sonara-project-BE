<?php

namespace Tests\Feature\Api;

use App\Models\RegistrationOtp;
use App\Models\User;
use App\Notifications\RegistrationOtpNotification;
use Database\Seeders\ApiMessageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApiMessageSeeder::class);
    }

    public function test_email_otp_must_be_verified_before_account_creation(): void
    {
        Notification::fake();
        $code = null;
        $response = $this->postJson('/api/register', [
            'name' => 'Email Listener',
            'channel' => 'email',
            'email' => 'listener@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertAccepted()
            ->assertJsonPath('data.channel', 'email');

        $this->assertDatabaseCount('users', 0);
        $challenge = RegistrationOtp::query()
            ->where('public_id', $response->json('data.challenge_id'))
            ->firstOrFail();
        Notification::assertSentOnDemand(
            RegistrationOtpNotification::class,
            function (RegistrationOtpNotification $notification, array $channels, object $notifiable) use (&$code): bool {
                preg_match('/\b(\d{6})\b/', implode(' ', $notification->toMail($notifiable)->introLines), $matches);
                $code = $matches[1] ?? null;

                return in_array('mail', $channels, true);
            },
        );
        $this->assertNotNull($code);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($code, $challenge->code_hash));
        $this->assertNotSame('StrongPassword123!', $challenge->password_hash);

        $this->postJson('/api/register/verify', [
            'challenge_id' => $response->json('data.challenge_id'),
            'code' => $code,
        ])
            ->assertCreated()
            ->assertJsonPath('message.code', 'auth.registration_verified')
            ->assertJsonStructure(['data' => ['user' => ['id', 'email'], 'token']]);

        $this->assertNotNull(User::query()->where('email', 'listener@example.com')->value('email_verified_at'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_icloud_email_registration_uses_email_delivery(): void
    {
        Notification::fake();

        $zaloCode = null;
        $response = $this->postJson('/api/register', [
            'name' => 'iCloud Listener',
            'channel' => 'icloud',
            'email' => 'listener@icloud.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])
            ->assertAccepted()
            ->assertJsonPath('data.channel', 'icloud')
            ->assertJsonPath('data.destination', 'l*******@icloud.com');

        Notification::assertSentOnDemand(RegistrationOtpNotification::class);
    }

    public function test_zalo_registration_sends_code_with_configured_template(): void
    {
        $zaloCode = null;
        config([
            'services.zalo.access_token' => 'zalo-access-token',
            'services.zalo.otp_template_id' => 'otp-template',
        ]);
        Http::fake([
            'business.openapi.zalo.me/message/template' => Http::response([
                'error' => 0,
                'message' => 'Success',
                'data' => ['msg_id' => 'message-id'],
            ]),
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'Zalo Listener',
            'channel' => 'zalo',
            'phone' => '+84901234567',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])
            ->assertAccepted()
            ->assertJsonPath('data.channel', 'zalo')
            ->assertJsonPath('data.destination', '********4567');

        Http::assertSent(function ($request) use (&$zaloCode): bool {
            $zaloCode = (string) ($request['template_data']['otp'] ?? '');

            return $request->url() === 'https://business.openapi.zalo.me/message/template'
                && $request['phone'] === '84901234567'
                && $request['template_id'] === 'otp-template'
                && preg_match('/^\d{6}$/', $zaloCode) === 1;
        });
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/register/verify', [
            'challenge_id' => $response->json('data.challenge_id'),
            'code' => $zaloCode,
        ])->assertCreated()
            ->assertJsonPath('data.user.phone', '+84901234567');
        $this->assertNotNull(User::query()->where('phone', '+84901234567')->value('phone_verified_at'));
    }

    public function test_incorrect_otp_attempts_are_limited(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/register', [
            'name' => 'Email Listener',
            'channel' => 'email',
            'email' => 'listener@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertAccepted();

        $challenge = RegistrationOtp::query()->where('public_id', $response->json('data.challenge_id'))->firstOrFail();
        $challenge->update(['code_hash' => \Illuminate\Support\Facades\Hash::make('123456')]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/api/register/verify', [
                'challenge_id' => $challenge->public_id,
                'code' => '000000',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/register/verify', [
            'challenge_id' => $challenge->public_id,
            'code' => '000000',
        ])->assertStatus(429)
            ->assertJsonPath('message.code', 'auth.registration_otp_locked');

        $this->assertDatabaseCount('users', 0);
    }
}
