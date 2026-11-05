<?php

namespace App\Notifications;

use App\Support\ApiResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationOtpNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = ApiResponse::messageText('auth.otp_email_subject');
        $body = str_replace('{code}', $this->code, ApiResponse::messageText('auth.otp_email_body'));

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello!')
            ->line($body);
    }
}
