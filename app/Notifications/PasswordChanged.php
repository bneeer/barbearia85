<?php

namespace App\Notifications;

use App\Services\EmailOutbox;
use Illuminate\Notifications\Notification;

class PasswordChanged extends Notification
{
    public function via(object $notifiable): array
    {
        return [AccountEmailChannel::class];
    }

    public function deliver(object $notifiable): void
    {
        app(EmailOutbox::class)->authentication($notifiable, 'password_changed');
    }
}
