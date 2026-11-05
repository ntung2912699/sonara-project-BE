<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SocialIdentityVerifier
{
    /**
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string}
     */
    public function verify(string $provider, string $token): array
    {
        return match ($provider) {
            'facebook' => $this->verifyFacebook($token),
            'tiktok' => $this->verifyTikTok($token),
            'apple' => $this->verifyApple($token),
        };
    }

    private function verifyFacebook(string $token): array
    {
        $appId = (string) config('services.facebook.app_id');
        $appSecret = (string) config('services.facebook.app_secret');
        abort_if($appId === '' || $appSecret === '', 503, 'Facebook sign-in is not configured.');

        $baseUrl = 'https://graph.facebook.com/'.config('services.facebook.graph_version');
        $debug = Http::timeout(8)->get($baseUrl.'/debug_token', [
            'input_token' => $token,
            'access_token' => $appId.'|'.$appSecret,
        ]);
        abort_unless($debug->successful(), 401, 'Facebook token verification failed.');

        $tokenData = $debug->json('data');
        abort_unless(
            is_array($tokenData)
                && ($tokenData['is_valid'] ?? false) === true
                && ($tokenData['app_id'] ?? null) === $appId
                && is_string($tokenData['user_id'] ?? null),
            401,
            'Facebook token is invalid for this application.',
        );

        $profile = Http::withToken($token)->timeout(8)->get($baseUrl.'/me', [
            'fields' => 'id,name,email',
        ]);
        abort_unless($profile->successful(), 401, 'Facebook profile verification failed.');

        $data = $profile->json();
        abort_unless(is_string($data['id'] ?? null) && $data['id'] === $tokenData['user_id'], 401);

        return [
            'sub' => $data['id'],
            'email' => is_string($data['email'] ?? null) ? $data['email'] : null,
            'email_verified' => is_string($data['email'] ?? null),
            'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
        ];
    }

    private function verifyTikTok(string $token): array
    {
        abort_if((string) config('services.tiktok.client_key') === '', 503, 'TikTok sign-in is not configured.');

        $response = Http::withToken($token)->timeout(8)->get(
            'https://open.tiktokapis.com/v2/user/info/',
            ['fields' => 'open_id,display_name,avatar_url'],
        );
        abort_unless($response->successful(), 401, 'TikTok token verification failed.');

        $user = $response->json('data.user');
        abort_unless(is_array($user) && is_string($user['open_id'] ?? null), 401, 'TikTok token is invalid.');

        return [
            'sub' => $user['open_id'],
            'email' => null,
            'email_verified' => false,
            'name' => is_string($user['display_name'] ?? null) ? $user['display_name'] : null,
        ];
    }

    private function verifyApple(string $identityToken): array
    {
        $clientId = (string) config('services.apple.client_id');
        abort_if($clientId === '', 503, 'Apple sign-in is not configured.');

        $parts = explode('.', $identityToken);
        abort_unless(count($parts) === 3, 401, 'Apple identity token is invalid.');
        $header = $this->decodeJsonPart($parts[0]);
        $claims = $this->decodeJsonPart($parts[1]);
        abort_unless(
            ($header['alg'] ?? null) === 'ES256'
                && is_string($header['kid'] ?? null)
                && ($claims['iss'] ?? null) === 'https://appleid.apple.com'
                && ($claims['aud'] ?? null) === $clientId
                && is_string($claims['sub'] ?? null)
                && is_numeric($claims['exp'] ?? null)
                && (int) $claims['exp'] > time(),
            401,
            'Apple identity token is invalid or expired.',
        );

        $keys = Cache::remember('apple-sign-in-jwks', now()->addHours(6), function (): array {
            $response = Http::timeout(8)->get('https://appleid.apple.com/auth/keys');
            abort_unless($response->successful(), 503, 'Apple signing keys are unavailable.');

            return $response->json('keys', []);
        });
        abort_unless(is_array($keys), 503, 'Apple signing keys are unavailable.');
        $key = collect($keys)->first(
            fn (mixed $key): bool => is_array($key) && ($key['kid'] ?? null) === $header['kid'],
        );
        abort_unless(
            is_array($key)
                && ($key['kty'] ?? null) === 'EC'
                && ($key['crv'] ?? null) === 'P-256'
                && is_string($key['x'] ?? null)
                && is_string($key['y'] ?? null),
            401,
            'Apple signing key is not recognized.',
        );

        $x = $this->base64UrlDecode($key['x']);
        $y = $this->base64UrlDecode($key['y']);
        abort_unless(strlen($x) === 32 && strlen($y) === 32, 401, 'Apple signing key is malformed.');
        $publicKey = $this->applePublicKeyPem($x, $y);
        $signature = $this->base64UrlDecode($parts[2]);
        abort_unless(strlen($signature) === 64, 401, 'Apple identity token signature is invalid.');
        $derSignature = $this->ecdsaSignatureToDer($signature);
        $verified = openssl_verify(
            $parts[0].'.'.$parts[1],
            $derSignature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        abort_unless($verified === 1, 401, 'Apple identity token signature is invalid.');

        $email = is_string($claims['email'] ?? null) ? $claims['email'] : null;
        $emailVerified = in_array($claims['email_verified'] ?? false, [true, 'true', 1, '1'], true);

        return [
            'sub' => $claims['sub'],
            'email' => $email,
            'email_verified' => $email !== null && $emailVerified,
            'name' => null,
        ];
    }

    private function decodeJsonPart(string $part): array
    {
        $decoded = json_decode($this->base64UrlDecode($part), true);
        abort_unless(is_array($decoded), 401, 'Identity token is malformed.');

        return $decoded;
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        abort_unless(is_string($decoded), 401, 'Identity token is malformed.');

        return $decoded;
    }

    private function applePublicKeyPem(string $x, string $y): string
    {
        $point = "\x04".$x.$y;
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function ecdsaSignatureToDer(string $signature): string
    {
        $r = $this->derInteger(substr($signature, 0, 32));
        $s = $this->derInteger(substr($signature, 32, 32));
        $sequence = $r.$s;

        return "\x30".chr(strlen($sequence)).$sequence;
    }

    private function derInteger(string $integer): string
    {
        $integer = ltrim($integer, "\x00");
        if ($integer === '' || (ord($integer[0]) & 0x80) !== 0) {
            $integer = "\x00".$integer;
        }

        return "\x02".chr(strlen($integer)).$integer;
    }
}
