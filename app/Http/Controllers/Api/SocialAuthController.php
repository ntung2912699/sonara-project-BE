<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\SocialIdentityVerifier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialAuthController extends Controller
{
    public function authenticate(
        Request $request,
        string $provider,
        SocialIdentityVerifier $verifier,
    ): JsonResponse {
        abort_unless(in_array($provider, ['facebook', 'tiktok', 'apple'], true), 404);
        $tokenField = $provider === 'apple' ? 'identity_token' : 'access_token';
        $validated = $request->validate([
            $tokenField => ['required', 'string', 'max:10000'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);
        $identity = $verifier->verify($provider, $validated[$tokenField]);

        $user = DB::transaction(function () use ($provider, $identity, $validated): User {
            $socialIdentity = SocialIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $identity['sub'])
                ->first();

            if ($socialIdentity !== null) {
                return $socialIdentity->user;
            }

            $email = $identity['email_verified'] ? $identity['email'] : null;
            $user = $email === null ? null : User::query()->where('email', $email)->first();
            $user ??= User::create([
                'name' => $identity['name'] ?? $validated['name'] ?? 'Music listener',
                'email' => $email,
            ]);
            if ($email !== null && $user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            abort_if(
                $user->socialIdentities()->where('provider', $provider)->exists(),
                409,
                'A different account for this provider is already linked.',
            );

            $user->socialIdentities()->create([
                'provider' => $provider,
                'provider_user_id' => $identity['sub'],
                'provider_email' => $identity['email'],
            ]);

            return $user;
        });

        if (! $user->is_active) {
            return ApiResponse::error('auth.account_locked', 403);
        }

        return ApiResponse::success(
            ['user' => $user, 'token' => $user->createToken('music-app')->plainTextToken],
            'auth.social_logged_in',
        );
    }
}
