<?php

namespace App\Services;

use App\Models\RegistrationOtp;
use App\Notifications\RegistrationOtpNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class RegistrationOtpDelivery
{
    public function send(RegistrationOtp $challenge, string $code): void
    {
        if (in_array($challenge->channel, ['email', 'icloud'], true)) {
            Notification::route('mail', $challenge->email)
                ->notify(new RegistrationOtpNotification($code));

            return;
        }

        $accessToken = (string) config('services.zalo.access_token');
        $templateId = (string) config('services.zalo.otp_template_id');
        abort_if($accessToken === '' || $templateId === '', 503, 'Zalo OTP delivery is not configured.');

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post('https://business.openapi.zalo.me/message/template', [
                'phone' => ltrim((string) $challenge->phone, '+'),
                'template_id' => $templateId,
                'template_data' => [
                    (string) config('services.zalo.otp_code_field') => $code,
                    (string) config('services.zalo.otp_expiry_field') => '10',
                ],
                'tracking_id' => $challenge->public_id,
            ]);

        $response->throw();
        abort_unless(
            is_array($response->json())
                && array_key_exists('error', $response->json())
                && (int) $response->json('error') === 0,
            502,
            'Zalo did not accept the OTP message.',
        );
    }
}
