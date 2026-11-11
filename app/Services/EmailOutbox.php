<?php

namespace App\Services;

use App\Jobs\SendEmailNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailOutbox
{
    public function create(array $attributes): Notification
    {
        $event = $attributes['event_id'] ?? (string) Str::uuid();
        $notification = Notification::create([
            ...$attributes, 'event_id' => $event, 'type' => 'email', 'status' => 'pending',
            'deduplication_key' => hash('sha256', $event.'|'.mb_strtolower($attributes['recipient'])),
            'next_attempt_at' => now(),
        ]);
        DB::afterCommit(function () use ($notification) {
            try {
                SendEmailNotification::dispatch($notification->id);
            } catch (\Throwable $exception) {
                // The durable outbox remains due for the scheduler if the queue is unavailable.
                Log::error('Email queue dispatch failed; scheduler recovery required.', [
                    'notification_id' => $notification->id, 'exception_class' => $exception::class,
                ]);
            }
        });

        return $notification;
    }

    public function authentication(User $user, string $template, ?string $url = null): void
    {
        DB::transaction(function () use ($user, $template, $url) {
            if ($template === 'password_changed') {
                Notification::where('user_id', $user->id)->where('template', 'reset_password')
                    ->where('status', 'pending')->update(['status' => 'skipped', 'failure_code' => 'superseded']);
            }
            Notification::where('user_id', $user->id)->where('template', $template)
                ->where('status', 'pending')->update(['status' => 'skipped', 'failure_code' => 'superseded']);
            $minutes = $template === 'verify_email'
                ? config('auth.verification.expire', 60)
                : config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
            $this->create([
                'recipient' => $user->email, 'user_id' => $user->id, 'audience' => 'account',
                'template' => $template, 'payload' => ['name' => $user->name],
                'secret' => $url ? ['url' => $url] : null,
                'expires_at' => $template === 'password_changed' ? now()->addDay() : now()->addMinutes($minutes),
            ]);
        });
    }
}
