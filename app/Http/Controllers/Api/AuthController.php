<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationOtp;
use App\Models\User;
use App\Services\RegistrationOtpDelivery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request, RegistrationOtpDelivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:email,icloud,zalo'],
            'email' => ['required_if:channel,email,icloud', 'nullable', 'email', 'max:255'],
            'phone' => ['required_if:channel,zalo', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $channel = $validated['channel'];
        $email = in_array($channel, ['email', 'icloud'], true)
            ? strtolower($validated['email'] ?? '')
            : null;
        $phone = $channel === 'zalo' ? $validated['phone'] ?? null : null;
        $destination = $email ?? $phone;
        $rateKey = 'registration-otp:'.hash('sha256', $destination);
        $cooldownKey = $rateKey.':cooldown';
        $ipRateKey = 'registration-otp-ip:'.hash('sha256', (string) $request->ip());

        abort_if(
            RateLimiter::tooManyAttempts($rateKey, 5)
                || RateLimiter::tooManyAttempts($cooldownKey, 1)
                || RateLimiter::tooManyAttempts($ipRateKey, 20),
            429,
            ApiResponse::messageText('auth.registration_otp_throttled'),
        );
        RateLimiter::hit($rateKey, 3600);
        RateLimiter::hit($cooldownKey, 60);
        RateLimiter::hit($ipRateKey, 3600);

        if (($email !== null && User::query()->where('email', $email)->exists())
            || ($phone !== null && User::query()->where('phone', $phone)->exists())) {
            return ApiResponse::error('auth.identifier_registered', 409);
        }

        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($validated, $channel, $email, $phone, $code): RegistrationOtp {
            RegistrationOtp::query()
                ->where(function ($query) use ($email, $phone): void {
                    if ($email !== null) {
                        $query->where('email', $email);
                    } else {
                        $query->where('phone', $phone);
                    }
                })
                ->delete();

            return RegistrationOtp::query()->create([
                'public_id' => (string) Str::uuid(),
                'name' => $validated['name'],
                'email' => $email,
                'phone' => $phone,
                'channel' => $channel,
                'password_hash' => Hash::make($validated['password']),
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
                'last_sent_at' => now(),
            ]);
        });

        $delivery->send($challenge, $code);

        return ApiResponse::success([
            'challenge_id' => $challenge->public_id,
            'channel' => $channel,
            'destination' => $this->maskDestination($destination, $channel),
            'expires_at' => $challenge->expires_at,
        ], 'auth.registration_otp_sent', 202);
    }

    public function resendRegistrationOtp(
        Request $request,
        RegistrationOtpDelivery $delivery,
    ): JsonResponse {
        $validated = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = RegistrationOtp::query()
            ->where('public_id', $validated['challenge_id'])
            ->firstOrFail();
        $rateKey = 'registration-otp-resend:'.$challenge->public_id;
        $ipRateKey = 'registration-otp-resend-ip:'.hash('sha256', (string) $request->ip());

        abort_if(
            RateLimiter::tooManyAttempts($rateKey, 5)
                || RateLimiter::tooManyAttempts($ipRateKey, 20)
                || $challenge->last_sent_at->gt(now()->subSeconds(60)),
            429,
            ApiResponse::messageText('auth.registration_otp_throttled'),
        );
        RateLimiter::hit($rateKey, 3600);
        RateLimiter::hit($ipRateKey, 3600);

        $code = (string) random_int(100000, 999999);
        $challenge->update([
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
            'attempts' => 0,
        ]);
        $delivery->send($challenge, $code);

        return ApiResponse::success([
            'challenge_id' => $challenge->public_id,
            'channel' => $challenge->channel,
            'destination' => $this->maskDestination(
                (string) ($challenge->email ?? $challenge->phone),
                $challenge->channel,
            ),
            'expires_at' => $challenge->expires_at,
        ], 'auth.registration_otp_sent', 202);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);
        $rateKey = 'registration-otp-verify:'.hash('sha256', $request->ip().$validated['challenge_id']);
        $ipRateKey = 'registration-otp-verify-ip:'.hash('sha256', (string) $request->ip());
        abort_if(
            RateLimiter::tooManyAttempts($rateKey, 10) || RateLimiter::tooManyAttempts($ipRateKey, 50),
            429,
            ApiResponse::messageText('auth.registration_otp_invalid'),
        );
        RateLimiter::hit($rateKey, 3600);
        RateLimiter::hit($ipRateKey, 3600);

        $result = DB::transaction(function () use ($validated): array {
            $challenge = RegistrationOtp::query()
                ->where('public_id', $validated['challenge_id'])
                ->lockForUpdate()
                ->first();

            if ($challenge === null
                || $challenge->expires_at->isPast()
                || $challenge->attempts >= 5) {
                return ['error' => 'auth.registration_otp_invalid'];
            }

            if (! Hash::check($validated['code'], $challenge->code_hash)) {
                $challenge->increment('attempts');

                return ['error' => $challenge->attempts >= 5
                    ? 'auth.registration_otp_locked'
                    : 'auth.registration_otp_invalid'];
            }

            $existingUser = User::query()
                ->when($challenge->email !== null, fn ($query) => $query->where('email', $challenge->email))
                ->when($challenge->phone !== null, fn ($query) => $query->where('phone', $challenge->phone))
                ->exists();
            if ($existingUser) {
                return ['error' => 'auth.identifier_registered'];
            }

            $user = User::query()->create([
                'name' => $challenge->name,
                'email' => $challenge->email,
                'phone' => $challenge->phone,
                'password' => $challenge->password_hash,
            ]);
            $user->forceFill([
                'email_verified_at' => $challenge->email !== null ? now() : null,
                'phone_verified_at' => $challenge->phone !== null ? now() : null,
            ])->save();
            $challenge->delete();

            return ['user' => $user];
        });

        if (isset($result['error'])) {
            $status = $result['error'] === 'auth.identifier_registered' ? 409 : 422;

            return ApiResponse::error(
                $result['error'],
                $result['error'] === 'auth.registration_otp_locked' ? 429 : $status,
            );
        }

        /** @var User $user */
        $user = $result['user'];

        return ApiResponse::success([
            'user' => $user,
            'token' => $user->createToken('music-app')->plainTextToken,
        ], 'auth.registration_verified', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return ApiResponse::error('auth.invalid_credentials', 401);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            return ApiResponse::error('auth.account_locked', 403);
        }

        return ApiResponse::success(
            ['user' => $user, 'token' => $user->createToken('music-app')->plainTextToken],
            'auth.logged_in',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'auth.logged_out');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($validated);

        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return ApiResponse::success(null, 'auth.reset_link_sent');
        }

        if ($status === Password::RESET_THROTTLED) {
            return ApiResponse::error('auth.reset_throttled', 429);
        }

        return ApiResponse::error('request.failed', 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('auth.password_reset_failed', 422);
        }

        return ApiResponse::success(null, 'auth.password_reset');
    }

    private function maskDestination(string $destination, string $channel): string
    {
        if ($channel === 'zalo') {
            return str_repeat('*', max(0, strlen($destination) - 4)).substr($destination, -4);
        }

        [$local, $domain] = explode('@', $destination, 2);

        return substr($local, 0, 1).str_repeat('*', max(1, strlen($local) - 1)).'@'.$domain;
    }
}
