<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BookingCancellationRequest;
use App\Models\Scopes\BarbershopScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingCancellations
{
    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function appointments(): Builder
    {
        return Appointment::withoutGlobalScope(BarbershopScope::class)->with([
            'client' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
            'barbershop', 'barber', 'checkout',
            'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
            'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
        ]);
    }

    public function request(string $email): void
    {
        if (! config('notifications.email_enabled')) {
            return;
        }

        $email = $this->normalizeEmail($email);
        DB::transaction(function () use ($email) {
            $appointments = $this->appointments()->where('status', 'scheduled')
                ->where('start_time', '>', now())
                ->whereHas('client', fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)
                    ->whereRaw('LOWER(TRIM(email)) = ?', [$email]))
                ->orderBy('start_time')->orderBy('id')->get();
            if ($appointments->isEmpty()) {
                return;
            }

            $token = bin2hex(random_bytes(32));
            $items = $appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'user_id' => $appointment->user_id,
                'barbershop_id' => $appointment->barbershop_id,
                'version' => $this->version($appointment),
                'eligible' => $this->reason($appointment) === null,
            ])->all();
            $grant = BookingCancellationRequest::create([
                'token_hash' => hash('sha256', $token),
                'email' => $email, 'appointments' => $items,
                'allows_all' => count($items) > 1 && collect($items)->every('eligible'),
                'expires_at' => now()->addMinutes(config('booking.cancellation_link_minutes')),
            ]);
            $links = [];
            foreach ($items as $item) {
                if ($item['eligible']) {
                    $links[$item['id']] = route('booking.cancellation.show', ['token' => $token, 'target' => $item['id']]);
                }
            }
            if ($grant->allows_all) {
                $links['all'] = route('booking.cancellation.show', ['token' => $token, 'target' => 'all']);
            }
            app(EmailOutbox::class)->create([
                'recipient' => $email, 'audience' => 'client',
                'template' => 'booking_cancellation_request',
                'payload' => [
                    'cancellation_request_id' => $grant->id,
                    'appointments' => $appointments->map(fn ($appointment) => $this->details($appointment))->all(),
                ],
                'secret' => ['links' => $links],
                'expires_at' => $grant->expires_at,
            ]);
        });
    }

    public function grant(string $token): ?BookingCancellationRequest
    {
        return BookingCancellationRequest::where('token_hash', hash('sha256', $token))->first();
    }

    public function selection(?BookingCancellationRequest $grant, string $target): ?Collection
    {
        if (! $grant || $grant->expires_at->lte(now())) {
            return null;
        }
        $items = collect($grant->appointments);
        if ($target === 'all') {
            return $grant->allows_all ? $items : null;
        }

        $selected = $items->filter(fn ($item) => (string) $item['id'] === $target && $item['eligible'])->values();

        return $selected->count() === 1 ? $selected : null;
    }

    public function preview(BookingCancellationRequest $grant, Collection $items): array
    {
        $appointments = $this->appointments()->whereIn('id', $items->pluck('id'))->get()->keyBy('id');
        $reason = $this->selectionReason($grant, $items, $appointments);
        $completed = $items->every(fn ($item) => in_array($item['id'], $grant->cancelled_ids ?? [], true));

        return [
            'canCancel' => ! $completed && $reason === null,
            'message' => $completed ? 'Cancelamento confirmado. Os horários não estão mais reservados.' : $reason,
            // Changed ownership must not expose the new client's appointment.
            'appointments' => $items->map(function ($item) use ($appointments, $grant) {
                $appointment = $appointments->get($item['id']);

                return $appointment && $this->owns($grant, $appointment)
                    ? $this->details($appointment) : null;
            })->filter()->values()->all(),
        ];
    }

    public function cancel(string $token, string $target): void
    {
        DB::transaction(function () use ($token, $target) {
            $grant = BookingCancellationRequest::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $items = $this->selection($grant, $target);
            if ($items === null) {
                throw ValidationException::withMessages(['cancellation' => 'Link inválido ou expirado. Solicite um novo e-mail.']);
            }

            // Match the barber-first locking used by booking, checkout and rescheduling.
            User::whereIn('id', $items->pluck('user_id'))->orderBy('id')->lockForUpdate()->get();
            $shops = Barbershop::whereIn('id', $items->pluck('barbershop_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $appointments = $this->appointments()->whereIn('id', $items->pluck('id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($appointments as $appointment) {
                $appointment->setRelation('barbershop', $shops->get($appointment->barbershop_id));
            }
            if ($reason = $this->selectionReason($grant, $items, $appointments)) {
                throw ValidationException::withMessages(['cancellation' => $reason]);
            }
            foreach ($appointments as $appointment) {
                $previousStart = $appointment->start_time->setTimezone($appointment->barbershop->timezone)->toIso8601String();
                $appointment->update(['status' => 'cancelled']);
                app(BookingNotifications::class)->changed($appointment, $appointment->barbershop, $previousStart, cancelledByClient: true);
                app(BookingNotifications::class)->synchronizePendingWhatsApp($appointment, $appointment->barbershop);
            }
            $grant->update(['cancelled_ids' => array_values(array_unique([
                ...($grant->cancelled_ids ?? []), ...$items->pluck('id')->all(),
            ]))]);
        }, 3);
    }

    private function selectionReason(BookingCancellationRequest $grant, Collection $items, Collection $appointments): ?string
    {
        if ($grant->expires_at->lte(now())) {
            return 'Link expirado. Solicite um novo e-mail.';
        }
        foreach ($items as $item) {
            $appointment = $appointments->get($item['id']);
            if (! $appointment || ! $this->owns($grant, $appointment)) {
                return 'Um dos agendamentos não está mais disponível. Nenhum cancelamento foi realizado nesta solicitação.';
            }
            if (! hash_equals($item['version'], $this->version($appointment))) {
                return 'Um dos agendamentos foi alterado. Solicite um novo e-mail. Nenhum cancelamento foi realizado nesta solicitação.';
            }
            if ($reason = $this->reason($appointment)) {
                return $reason.' Nenhum cancelamento foi realizado nesta solicitação.';
            }
        }

        return null;
    }

    private function owns(BookingCancellationRequest $grant, Appointment $appointment): bool
    {
        return $appointment->client && $this->normalizeEmail($appointment->client->email) === $grant->email;
    }

    private function version(Appointment $appointment): string
    {
        return hash('sha256', json_encode([
            $appointment->client_id, $appointment->barbershop_id, $appointment->user_id,
            $appointment->start_time->getTimestamp(), $appointment->end_time->getTimestamp(),
            $appointment->service_id, $appointment->services->pluck('id')->sort()->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function reason(Appointment $appointment): ?string
    {
        if ($appointment->status === 'cancelled') {
            return 'Este agendamento já foi cancelado.';
        }
        if ($appointment->status !== 'scheduled' || $appointment->checkout || ! $appointment->barbershop) {
            return 'Este agendamento não está disponível para cancelamento.';
        }
        if ($appointment->start_time->lte(now())) {
            return 'Este atendimento já começou e não pode ser cancelado pelo autosserviço.';
        }
        if (now()->gt($appointment->start_time->subHours($appointment->barbershop->cancellation_notice_hours))) {
            return 'O prazo para cancelamento pelo autosserviço encerrou. Entre em contato com a unidade.';
        }

        return null;
    }

    private function details(Appointment $appointment): array
    {
        $shop = $appointment->barbershop;

        return [
            'id' => $appointment->id,
            'service' => $appointment->services->isNotEmpty()
                ? $appointment->services->pluck('name')->implode(' + ') : $appointment->service?->name,
            'barbershop' => $shop->name, 'barber' => $appointment->barber?->name,
            'starts_at' => $appointment->start_time->setTimezone($shop->timezone)->format('d/m/Y H:i'),
            'deadline' => $appointment->start_time->subHours($shop->cancellation_notice_hours)
                ->setTimezone($shop->timezone)->format('d/m/Y H:i'),
            'timezone' => $shop->timezone, 'phones' => $shop->phones ?? [],
            'reason' => $this->reason($appointment),
        ];
    }
}
