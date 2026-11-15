<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\ClientPortalGrant;
use App\Models\Notification;
use App\Models\Scopes\BarbershopScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClientPortal
{
    public function request(string $address): void
    {
        $email = mb_strtolower(trim($address));
        $key = hash_hmac('sha256', $email, config('app.key'));

        DB::transaction(function () use ($email, $key) {
            if (! Client::withoutGlobalScope(BarbershopScope::class)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
                return;
            }

            DB::table('client_portal_grants')->insertOrIgnore([
                'email_key' => $key, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $grant = ClientPortalGrant::where('email_key', $key)->lockForUpdate()->firstOrFail();
            $notification = $grant->notification_id
                ? Notification::whereKey($grant->notification_id)->lockForUpdate()->first() : null;
            $definitelyFailed = $notification && $notification->status === 'failed'
                && in_array($notification->failure_code, ['smtp_rejected', 'smtp_send_cancelled', 'smtp_temporary_rejection', 'unsupported_mailer', 'attempts_exhausted'], true);
            if ($notification?->status === 'processing' || ($grant->expires_at?->isFuture() && ! $definitelyFailed)) {
                return;
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = now()->addHour();
            $grant->update([
                'email' => $email, 'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt, 'notification_id' => null,
            ]);
            $notification = app(EmailOutbox::class)->create([
                'recipient' => $email, 'audience' => 'client', 'template' => 'client_portal_access',
                'payload' => ['name' => 'cliente'], 'secret' => ['url' => route('client-portal.show', ['token' => $token])],
                'expires_at' => $expiresAt,
            ]);
            $grant->update(['notification_id' => $notification->id]);
        }, 3);
    }

    public function grant(string $token): ?ClientPortalGrant
    {
        return ClientPortalGrant::where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())->first();
    }

    private function appointments(string $email): Builder
    {
        return Appointment::withoutGlobalScope(BarbershopScope::class)
            ->whereHas('client', fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email]));
    }

    public function dashboard(ClientPortalGrant $grant, LoyaltyProgram $loyalty): array
    {
        $base = $this->appointments($grant->email);
        $completed = (clone $base)->where('status', 'completed');
        $ids = (clone $completed)->select('appointments.id');
        $serviceIds = DB::table('appointment_service')->whereIn('appointment_id', $ids)
            ->select('service_id')
            ->unionAll(DB::table('appointments')->whereIn('id', $ids)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointment_service')
                    ->whereColumn('appointment_service.appointment_id', 'appointments.id'))
                ->select('service_id'));
        $favoriteService = DB::query()->fromSub($serviceIds, 'used_services')
            ->join('services', 'services.id', '=', 'used_services.service_id')
            ->select('services.name')->selectRaw('COUNT(*) as total')
            ->groupBy('services.id', 'services.name')->orderByDesc('total')->orderBy('services.name')->orderBy('services.id')->first();
        $favoriteBarber = (clone $completed)->join('users', 'users.id', '=', 'appointments.user_id')
            ->select('users.name')->selectRaw('COUNT(*) as total')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->orderBy('users.name')->orderBy('users.id')->first();
        $relations = [
            'barbershop', 'barber',
            'services' => fn ($q) => $q->withoutGlobalScope(BarbershopScope::class),
            'service' => fn ($q) => $q->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
        ];
        $upcoming = (clone $base)->with($relations)->where('status', 'scheduled')
            ->where('start_time', '>', now())->orderBy('start_time')->orderBy('id')->get()->map($this->details(...));
        $history = (clone $base)->with($relations)->orderByDesc('start_time')->orderByDesc('id')
            ->paginate(15)->withQueryString()->through($this->details(...));

        return [
            'totalCompleted' => (clone $completed)->count(),
            'favoriteService' => $favoriteService?->name,
            'favoriteBarber' => $favoriteBarber?->name,
            'loyalty' => $loyalty->forEmail($grant->email),
            'upcoming' => $upcoming,
            'history' => $history,
        ];
    }

    private function details(Appointment $appointment): array
    {
        $shop = $appointment->barbershop;

        return [
            'id' => $appointment->id,
            'service' => $appointment->services->isNotEmpty()
                ? $appointment->services->pluck('name')->implode(' + ') : ($appointment->service?->name ?? 'Serviço indisponível'),
            'barbershop' => $shop?->name ?? 'Unidade indisponível',
            'barber' => $appointment->barber?->name ?? 'Profissional indisponível',
            'starts_at' => $appointment->start_time->setTimezone($shop?->timezone ?? config('app.display_timezone', 'America/Sao_Paulo'))->format('d/m/Y H:i'),
            'timezone' => $shop?->timezone ?? config('app.display_timezone', 'America/Sao_Paulo'),
            'status' => $appointment->status,
        ];
    }
}
