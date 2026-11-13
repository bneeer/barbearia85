<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Scopes\BarbershopScope;
use Illuminate\Support\Collection;

class ClientPrivacyLookup
{
    public function clients()
    {
        return Client::withoutGlobalScope(BarbershopScope::class)->withTrashed();
    }

    public function client(int $id): Client
    {
        return $this->clients()->with('barbershop:id,name,timezone')->findOrFail($id);
    }

    public function matches(Client $client): Collection
    {
        $email = mb_strtolower(trim($client->email ?? ''));
        $phone = trim($client->phone ?? '');
        if ($email === '' && $phone === '') {
            return collect();
        }

        return $this->clients()->with('barbershop:id,name')->whereKeyNot($client->id)
            ->where(function ($query) use ($email, $phone) {
                if ($email !== '') {
                    $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                }
                if ($phone !== '') {
                    $query->orWhere('phone', $phone);
                }
            })->orderBy('name')->limit(51)->get()
            ->map(fn (Client $match) => [
                'id' => $match->id,
                'name' => $match->name,
                'email' => $match->email,
                'phone' => $match->phone,
                'unit' => $match->barbershop?->name,
                'archived' => $match->trashed(),
                'email_match' => $email !== '' && mb_strtolower(trim($match->email ?? '')) === $email,
                'phone_match' => $phone !== '' && trim($match->phone ?? '') === $phone,
            ]);
    }

    public function preview(Client $client): array
    {
        $appointments = Appointment::withoutGlobalScope(BarbershopScope::class)->withTrashed()
            ->where('client_id', $client->id);
        $ids = (clone $appointments)->select('id');

        return [
            'appointments' => (clone $appointments)->count(),
            'future_scheduled' => (clone $appointments)->whereNull('deleted_at')
                ->where('status', 'scheduled')->where('start_time', '>', now())->count(),
            'checkouts' => \DB::table('appointment_checkouts')->whereIn('appointment_id', $ids)->count(),
            'payments' => \DB::table('payments')->whereIn('appointment_id', $ids)->count(),
            'linked_notifications' => Notification::where('client_id', $client->id)
                ->orWhereIn('appointment_id', $ids)->count(),
        ];
    }
}
