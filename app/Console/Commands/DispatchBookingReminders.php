<?php

namespace App\Console\Commands;

use App\Services\BookingNotifications;
use Illuminate\Console\Command;

class DispatchBookingReminders extends Command
{
    protected $signature = 'notifications:reminders';

    protected $description = 'Queue appointment reminder emails due 48 hours before service';

    public function handle(BookingNotifications $notifications): int
    {
        $count = $notifications->queueDueReminders();
        $this->info("Lembretes enfileirados: {$count}.");

        return self::SUCCESS;
    }
}
