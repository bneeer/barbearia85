<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Scopes\BarbershopScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingNotifications
{
    public function confirmation(
        Appointment $appointment,
        Client $client,
        Barbershop $shop,
        User $barber,
        Collection $services,
        CarbonImmutable $start,
    ): void {
        $enabled = (bool) config('notifications.email_enabled');
        DB::table('appointments')->where('id', $appointment->id)
            ->update(['email_notifications_enabled' => $enabled]);
        $payload = $this->confirmationPayload($client, $shop, $barber, $services, $start);
        if (! $enabled) {
            Notification::create([
                'appointment_id' => $appointment->id, 'client_id' => $client->id,
                'type' => 'email', 'template' => 'booking_confirmation', 'status' => 'pending',
                'payload' => [...$payload, 'recipient' => $client->email],
                'failure_code' => 'email_disabled',
            ]);

        } else {
            $this->event($appointment, $shop, $barber, $client, $payload, 'booking_confirmation');
        }
        // Preserve the future WhatsApp channel without making it eligible for email delivery.
        Notification::create([
            'appointment_id' => $appointment->id, 'client_id' => $client->id,
            'type' => 'whatsapp', 'template' => 'booking_confirmation', 'status' => 'pending',
            'payload' => [...$payload, 'recipient' => $client->phone],
        ]);
    }

    public function retroactiveCandidates(): Builder
    {
        return Appointment::withoutGlobalScope(BarbershopScope::class)
            ->where('email_notifications_enabled', false)
            ->where('status', 'scheduled')->where('start_time', '>', now(new \DateTimeZone('America/Sao_Paulo')))
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('notifications')
                    ->whereColumn('notifications.appointment_id', 'appointments.id')
                    ->where('type', 'email')
                    ->where(function ($query) {
                        $query->whereNotNull('deduplication_key')->orWhereNotNull('provider_id')
                            ->orWhereNotNull('sent_at')->orWhereNotNull('first_attempt_at')
                            ->orWhere('attempts', '>', 0)
                            ->orWhereIn('status', ['sent', 'processing', 'simulated', 'failed'])
                            ->orWhereIn('failure_code', ['provider_result_unknown', 'smtp_result_unknown', 'worker_interrupted', 'reconciliation_required'])
                            ->orWhereExists(function ($query) {
                                $query->selectRaw('1')->from('notification_attempts')
                                    ->whereColumn('notification_attempts.notification_id', 'notifications.id');
                            });
                    });
            });
    }

    public function retroactiveConfirmation(int $id): bool
    {
        if (! config('notifications.email_enabled')) {
            throw new \LogicException('Enable email notifications before queuing retroactive confirmations.');
        }

        return DB::transaction(function () use ($id) {
            $appointment = $this->retroactiveCandidates()->whereKey($id)->lockForUpdate()->first();
            if (! $appointment) {
                return false;
            }
            $shop = $appointment->barbershop()->firstOrFail();
            $client = $appointment->client()->withoutGlobalScopes()->firstOrFail();
            $barber = $appointment->barber()->firstOrFail();
            $services = $appointment->services()->withoutGlobalScopes()->get();
            if ($services->isEmpty()) {
                $services = collect([$appointment->service()->withoutGlobalScopes()->withTrashed()->firstOrFail()]);
            }
            $payload = $this->confirmationPayload(
                $client, $shop, $barber, $services,
                CarbonImmutable::instance($appointment->start_time)->setTimezone($shop->timezone),
            );
            $appointment->email_notifications_enabled = true;
            $appointment->save();
            Notification::where('appointment_id', $id)->where('type', 'email')
                ->whereNull('deduplication_key')->where('status', 'pending')
                ->update(['status' => 'skipped', 'failure_code' => 'superseded']);
            $this->event($appointment, $shop, $barber, $client, $payload, 'booking_confirmation');

            return true;
        });
    }

    public function queueDueReminders(): int
    {
        if (! config('notifications.email_enabled')) {
            return 0;
        }

        $now = now();
        $queued = 0;
        Appointment::withoutGlobalScope(BarbershopScope::class)
            ->where('email_notifications_enabled', true)
            ->where('status', 'scheduled')
            ->whereBetween('start_time', [$now->copy()->addHours(48)->subMinute(), $now->copy()->addHours(48)])
            ->orderBy('id')
            ->chunkById(100, function ($appointments) use ($now, &$queued) {
                foreach ($appointments as $appointment) {
                    if ($this->queueReminder($appointment->id, $now)) {
                        $queued++;
                    }
                }
            });

        return $queued;
    }

    private function queueReminder(int $id, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($id, $now) {
            $appointment = Appointment::withoutGlobalScope(BarbershopScope::class)
                ->whereKey($id)->lockForUpdate()->first();
            if (! $appointment || ! $appointment->email_notifications_enabled
                || $appointment->status !== 'scheduled'
                || $appointment->start_time->lt($now->copy()->addHours(48)->subMinute())
                || $appointment->start_time->gt($now->copy()->addHours(48))
                || $appointment->created_at->gt($appointment->start_time->copy()->subHours(48))) {
                return false;
            }

            $event = $this->reminderEventId($appointment);
            if (Notification::where('event_id', $event)->where('template', 'booking_reminder')->exists()) {
                return false;
            }

            $shop = $appointment->barbershop()->firstOrFail();
            $client = $appointment->client()->withoutGlobalScopes()->firstOrFail();
            $barber = $appointment->barber()->firstOrFail();
            $services = $appointment->services()->withoutGlobalScopes()->get();
            if ($services->isEmpty()) {
                $services = collect([$appointment->service()->withoutGlobalScopes()->withTrashed()->firstOrFail()]);
            }
            $start = CarbonImmutable::instance($appointment->start_time)->setTimezone($shop->timezone);
            $payload = $this->confirmationPayload($client, $shop, $barber, $services, $start);
            $cancelUrl = app(BookingCancellations::class)->reminderLink($appointment);

            app(EmailOutbox::class)->create([
                'appointment_id' => $appointment->id,
                'client_id' => $client->id,
                'barbershop_id' => $shop->id,
                'recipient' => $client->email,
                'audience' => 'client',
                'event_id' => $event,
                'template' => 'booking_reminder',
                'payload' => [...$payload, 'recipient' => $client->email],
                'secret' => $cancelUrl ? ['cancellation_url' => $cancelUrl] : null,
                'expires_at' => $appointment->start_time,
            ]);

            return true;
        }, 3);
    }

    private function reminderEventId(Appointment $appointment): string
    {
        $hash = substr(hash('sha256', "booking_reminder|{$appointment->id}|{$appointment->start_time->getTimestamp()}"), 0, 32);
        $hash[12] = '5';
        $hash[16] = '8';

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'
            .substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    private function confirmationPayload(
        Client $client, Barbershop $shop, User $barber, Collection $services, CarbonImmutable $start,
    ): array {
        return [
            'name' => $client->name, 'barbershop' => $shop->name,
            'address' => $shop->address, 'phones' => $shop->phones,
            'service' => $services->pluck('name')->implode(' + '),
            'services' => $services->map(fn ($service) => $service->only(['name', 'duration', 'price']))->values()->all(),
            'duration' => (int) $services->sum('duration'),
            'price' => (float) $services->sum(fn ($service) => (float) $service->price),
            'barber' => $barber->name, 'starts_at' => $start->toIso8601String(),
            'timezone' => $shop->timezone,
        ];
    }

    public function changed(Appointment $appointment, Barbershop $shop, string $previousStart, bool $cancelledByClient = false): void
    {
        $cancelledByClient = $cancelledByClient && $appointment->status === 'cancelled';
        Notification::where('appointment_id', $appointment->id)->where('type', 'email')
            ->where('template', 'booking_reminder')->where('status', 'pending')
            ->update(['status' => 'skipped', 'failure_code' => 'superseded']);
        if ((! $appointment->email_notifications_enabled && ! $cancelledByClient) || ! config('notifications.email_enabled')) {
            return;
        }
        $client = $appointment->client()->withoutGlobalScopes()->firstOrFail();
        $barber = $appointment->barber()->firstOrFail();
        $services = $appointment->services()->withoutGlobalScopes()->get();
        if ($services->isEmpty()) {
            $services = collect([$appointment->service()->withoutGlobalScopes()->withTrashed()->firstOrFail()]);
        }
        $payload = [
            'name' => $client->name, 'barbershop' => $shop->name,
            'address' => $shop->address, 'phones' => $shop->phones,
            'service' => $services->pluck('name')->implode(' + '),
            'barber' => $barber->name, 'duration' => (int) $services->sum('duration'),
            'starts_at' => $appointment->start_time->setTimezone($shop->timezone)->toIso8601String(),
            'previous_starts_at' => $previousStart, 'timezone' => $shop->timezone,
            'cancelled_by_client' => $cancelledByClient,
        ];
        Notification::where('appointment_id', $appointment->id)->where('type', 'email')
            ->where('status', 'pending')->update(['status' => 'skipped', 'failure_code' => 'superseded']);
        $this->event($appointment, $shop, $barber, $client, $payload,
            $appointment->status === 'cancelled' ? 'booking_cancellation' : 'booking_rescheduled');
    }

    private function event(
        Appointment $appointment, Barbershop $shop, User $barber, Client $client,
        array $payload, string $template,
    ): void {
        $event = (string) Str::uuid();
        $recipients = collect([
            ['email' => $client->email, 'audience' => 'client', 'user_id' => null],
            ['email' => $barber->email, 'audience' => 'barber', 'user_id' => $barber->id],
        ])->concat($shop->users()->where('role', 'admin')->get()->map(fn ($admin) => [
            'email' => $admin->email, 'audience' => 'admin', 'user_id' => $admin->id,
        ]))->unique(fn ($recipient) => mb_strtolower($recipient['email']));
        foreach ($recipients as $recipient) {
            $data = $payload;
            if ($recipient['audience'] !== 'client') {
                unset($data['price'], $data['services']);
            }
            app(EmailOutbox::class)->create([
                'appointment_id' => $appointment->id, 'client_id' => $client->id,
                'barbershop_id' => $shop->id, 'user_id' => $recipient['user_id'],
                'recipient' => $recipient['email'], 'audience' => $recipient['audience'],
                'event_id' => $event, 'template' => $template,
                'payload' => [...$data, 'recipient' => $recipient['email']],
                'expires_at' => $template === 'booking_cancellation' ? now(new \DateTimeZone('America/Sao_Paulo'))->addDay() : $appointment->start_time,
            ]);
        }
    }

    public function synchronizePendingWhatsApp(Appointment $appointment, Barbershop $shop): void
    {
        $notifications = Notification::where('appointment_id', $appointment->id)
            ->where('type', 'whatsapp')->where('status', 'pending')->get();
        foreach ($notifications as $notification) {
            $payload = $notification->payload;
            $payload['starts_at'] = $appointment->start_time->setTimezone($shop->timezone)->toIso8601String();
            $payload['status'] = $appointment->status;
            $notification->update([
                'payload' => $payload,
                'template' => $appointment->status === 'cancelled' ? 'booking_cancellation' : 'booking_confirmation',
            ]);
        }
    }
}
