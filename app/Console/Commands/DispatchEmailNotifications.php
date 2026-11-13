<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailNotification;
use App\Models\Notification;
use Illuminate\Console\Command;

class DispatchEmailNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Queue due email notifications and recover interrupted deliveries';

    public function handle(): int
    {
        if (! config('notifications.email_enabled')) {
            $this->info('Email delivery is disabled.');

            return self::SUCCESS;
        }
        Notification::where('type', 'email')->whereNotNull('deduplication_key')
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('status', 'pending')->where('next_attempt_at', '<=', now(new \DateTimeZone('America/Sao_Paulo')));
                })->orWhere(function ($query) {
                    $query->where('status', 'processing')->where('locked_until', '<=', now(new \DateTimeZone('America/Sao_Paulo')));
                });
            })->chunkById(100, function ($records) {
                foreach ($records as $record) {
                    SendEmailNotification::dispatch($record->id);
                }
            });

        return self::SUCCESS;
    }
}
