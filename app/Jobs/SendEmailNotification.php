<?php

namespace App\Jobs;

use App\Services\EmailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendEmailNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $notificationId)
    {
        $this->onQueue(config('notifications.queue'));
        $this->onConnection(config('notifications.connection'));
    }

    public function handle(EmailDelivery $delivery): void
    {
        $delivery->send($this->notificationId);
    }
}
