<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $shops = Barbershop::where('is_open', true)->with([
            'photos',
            'users' => fn ($query) => $query->where('role', 'barber')->orderBy('name'),
            'users.services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)
                ->where('is_active', true)->where('duration', '>', 0),
        ])->orderBy('name')->get();

        return Inertia::render('Booking', [
            'csrfToken' => $request->session()->token(),
            'barbershops' => $shops->map(fn ($shop) => [
                'id' => $shop->id,
                'name' => $shop->name,
                'description' => $shop->description,
                'address' => $shop->address,
                'phones' => $shop->phones ?? [],
                'timezone' => $shop->timezone,
                'today' => CarbonImmutable::now($shop->timezone)->format('Y-m-d'),
                'photos' => $shop->photos->map(fn ($photo) => ['url' => $photo->url]),
                'barbers' => $shop->users->map(fn ($barber) => [
                    'id' => $barber->id, 'name' => $barber->name, 'avatar' => $barber->avatar,
                    'service_ids' => $barber->services->modelKeys(),
                ]),
                'services' => $shop->users->flatMap->services->unique('id')->sortBy('name')->values()
                    ->map(fn ($service) => $service->only(['id', 'name', 'description', 'duration', 'price'])),
            ]),
        ]);
    }

    private function selection(Request $request): array
    {
        $data = $request->validate([
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'service_id' => ['required', 'integer'],
            'barber_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $shop = Barbershop::findOrFail($data['barbershop_id']);
        $service = Service::withoutGlobalScope(BarbershopScope::class)->where('is_active', true)
            ->where('duration', '>', 0)->find($data['service_id']);
        if (! $shop->is_open || ! $service) {
            throw ValidationException::withMessages(['service_id' => 'Esta unidade ou serviço não está disponível.']);
        }

        return [$data, $shop, $service];
    }

    public function availability(Request $request, BookingAvailability $availability)
    {
        [$data, $shop, $service] = $this->selection($request);

        return response()->json(['slots' => $availability->slots(
            $shop, $service, $data['date'], $data['barber_id'] ?? null
        )]);
    }

    public function store(Request $request, BookingAvailability $availability)
    {
        $customer = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?(?=(?:\D*\d){10,15}\D*$)[\d\s().-]+$/'],
            'barber_id' => ['required', 'integer', 'exists:users,id'],
            'time' => ['required', 'date_format:H:i'],
        ], ['phone.regex' => 'Informe um telefone válido com DDD.']);
        [$data, $shop, $service] = $this->selection($request);

        $appointment = DB::transaction(function () use ($customer, $data, $shop, $service, $availability) {
            // Serialize confirmations for this barber, including bookings in other units.
            $barber = User::whereKey($data['barber_id'])->lockForUpdate()->firstOrFail();
            $slots = $availability->slots($shop, $service, $data['date'], $barber->id);
            if (! collect($slots)->contains(fn ($slot) => $slot['time'] === $customer['time'])) {
                throw ValidationException::withMessages([
                    'time' => 'Este horário não está mais disponível. Escolha outro horário.',
                ]);
            }

            $client = Client::create([
                'barbershop_id' => $shop->id,
                'name' => $customer['name'], 'email' => $customer['email'], 'phone' => $customer['phone'],
            ]);
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $data['date'].' '.$customer['time'], $shop->timezone);
            $appointment = Appointment::create([
                'barbershop_id' => $shop->id, 'client_id' => $client->id,
                'user_id' => $barber->id, 'service_id' => $service->id,
                'start_time' => $start->utc(), 'end_time' => $start->addMinutes($service->duration)->utc(),
                'status' => 'scheduled',
            ]);

            foreach (['email', 'whatsapp'] as $channel) {
                Notification::create([
                    'appointment_id' => $appointment->id, 'client_id' => $client->id,
                    'type' => $channel, 'template' => 'booking_confirmation', 'status' => 'pending',
                    'payload' => [
                        'name' => $client->name,
                        'recipient' => $channel === 'email' ? $client->email : $client->phone,
                        'barbershop' => $shop->name, 'address' => $shop->address, 'phones' => $shop->phones,
                        'service' => $service->name, 'duration' => $service->duration, 'price' => $service->price,
                        'barber' => $barber->name, 'starts_at' => $start->toIso8601String(),
                        'timezone' => $shop->timezone,
                    ],
                ]);
            }

            return $appointment;
        }, 3);

        return response()->json(['id' => $appointment->id], 201);
    }
}
