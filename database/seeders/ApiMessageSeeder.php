<?php

namespace Database\Seeders;

use App\Models\ApiMessage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class ApiMessageSeeder extends Seeder
{
    public function run(): void
    {
        $messages = [
            'common.ok' => 'Request completed successfully.',
            'common.created' => 'Resource created successfully.',
            'common.deleted' => 'Resource deleted successfully.',
            'auth.registered' => 'Account created successfully.',
            'auth.registration_otp_sent' => 'A verification code has been sent to the selected destination.',
            'auth.registration_verified' => 'Account verified and created successfully.',
            'auth.registration_otp_invalid' => 'The verification code is invalid or expired.',
            'auth.registration_otp_locked' => 'Too many incorrect verification codes. Request a new code to continue.',
            'auth.registration_otp_throttled' => 'Please wait before requesting another verification code.',
            'auth.identifier_registered' => 'An account already exists for this email address or phone number.',
            'auth.otp_email_subject' => 'Your Music App verification code',
            'auth.otp_email_body' => 'Your Music App verification code is {code}. It expires in 10 minutes.',
            'auth.logged_in' => 'Logged in successfully.',
            'auth.logged_out' => 'Logged out successfully.',
            'auth.invalid_credentials' => 'The provided credentials are incorrect.',
            'auth.social_logged_in' => 'Social sign-in completed successfully.',
            'auth.provider_token_invalid' => 'The identity provider token is invalid or expired.',
            'auth.reset_link_sent' => 'If the account exists, a password reset link has been sent.',
            'auth.reset_throttled' => 'A password reset email was recently requested. Please wait before trying again.',
            'auth.password_reset' => 'Password reset successfully.',
            'auth.password_reset_failed' => 'The password reset link is invalid or expired.',
            'profile.updated' => 'Profile updated successfully.',
            'track.downloaded' => 'Track download started.',
            'favorite.saved' => 'Track added to favorites.',
            'favorite.removed' => 'Track removed from favorites.',
            'playlist.created' => 'Playlist created successfully.',
            'playlist.updated' => 'Playlist updated successfully.',
            'playlist.deleted' => 'Playlist deleted successfully.',
            'playlist.track_added' => 'Track added to playlist.',
            'playlist.track_removed' => 'Track removed from playlist.',
            'tiktok.review_required' => 'Submitted for rights review. Audio is not extracted or downloaded automatically.',
            'audio.queued' => 'Audio processing has been queued.',
            'validation.failed' => 'The supplied data is invalid.',
            'auth.unauthenticated' => 'Authentication is required.',
            'resource.not_found' => 'The requested resource was not found.',
            'request.forbidden' => 'This action is not allowed.',
            'request.failed' => 'The request could not be completed.',
            'request.rate_limited' => 'Too many requests. Please try again later.',
            'service.unavailable' => 'This sign-in provider is not currently configured or available.',
            'service.otp_delivery_failed' => 'We could not deliver the verification code. Please try again later.',
        ];

        foreach ($messages as $code => $text) {
            Cache::forget("api-message:en:{$code}");
            ApiMessage::query()->updateOrCreate(
                ['code' => $code, 'locale' => 'en'],
                ['text' => $text],
            );
        }
    }
}
