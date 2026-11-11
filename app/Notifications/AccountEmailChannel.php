<?php

namespace App\Notifications;

use App\Services\EmailOutbox;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

class AccountEmailChannel
{
    public function send($notifiable, Notification $notification)
    {
        if ($notification instanceof PasswordChanged) {
            $notification->deliver($notifiable);

            return;
        }
        if ($notification instanceof VerifyEmail || $notification instanceof ResetPassword) {
            $mail = $notification->toMail($notifiable);
            app(EmailOutbox::class)->authentication(
                $notifiable,
                $notification instanceof VerifyEmail ? 'verify_email' : 'reset_password',
                $mail->actionUrl,
            );

            return;
        }

        return app(MailChannel::class)->send($notifiable, $notification);
    }
}
