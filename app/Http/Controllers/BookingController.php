<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Services\BookingAvailability;
use App\Services\BookingNotifications;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'service_ids' => ['nullable', 'array', 'min:1', 'distinct'],
            'service_ids.*' => ['required', 'integer', 'distinct'],
            'service_id' => ['required_without:service_ids', 'integer'],
            'barber_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $shop = Barbershop::findOrFail($data['barbershop_id']);
        $serviceIds = $data['service_ids'] ?? [$data['service_id']];
        $availableServices = Service::withoutGlobalScope(BarbershopScope::class)
            ->where('is_active', true)->where('duration', '>', 0)
            ->whereIn('id', $serviceIds)->get()->keyBy('id');
        if (! $shop->is_open || $availableServices->count() !== count($serviceIds)) {
            $key = isset($data['service_ids']) ? 'service_ids' : 'service_id';
            throw ValidationException::withMessages([$key => 'Esta unidade ou serviço não está disponível.']);
        }

        $services = collect($serviceIds)->map(fn ($id) => $availableServices->get($id));

        return [$data, $shop, $services];
    }

    public function availability(Request $request, BookingAvailability $availability)
    {
        [$data, $shop, $services] = $this->selection($request);

        return response()->json(['slots' => $availability->slots(
            $shop, $services, $data['date'], $data['barber_id'] ?? null
        )]);
    }

    public function store(Request $request, BookingAvailability $availability)
    {
        if ($request->exists('items')) {
            return $this->storeItems($request, $availability);
        }

        $customer = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64', new InternationalPhone],
            'barber_id' => ['required', 'integer', 'exists:users,id'],
            'time' => ['required', 'date_format:H:i'],
        ]);
        $customer['phone'] = PhoneNumber::normalize($customer['phone']);
        [$data, $shop, $services] = $this->selection($request);

        $appointment = DB::transaction(function () use ($customer, $data, $shop, $services, $availability) {
            // Serialize confirmations for this barber, including bookings in other units.
            $barber = User::whereKey($data['barber_id'])->lockForUpdate()->firstOrFail();
            $slots = $availability->slots($shop, $services, $data['date'], $barber->id);
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
            $duration = (int) $services->sum('duration');
            $appointment = Appointment::create([
                'barbershop_id' => $shop->id, 'client_id' => $client->id,
                'user_id' => $barber->id, 'service_id' => $services->first()->id,
                'start_time' => $start->utc(), 'end_time' => $start->addMinutes($duration)->utc(),
                'status' => 'scheduled',
            ]);
            $appointment->services()->sync($services->pluck('id')->all());
            $this->queueNotifications($appointment, $client, $shop, $barber, $services, $start);

            return $appointment;
        }, 3);

        return response()->json(['id' => $appointment->id], 201);
    }

    private function storeItems(Request $request, BookingAvailability $availability)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64', new InternationalPhone],
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'items' => ['required', 'array', 'list', 'min:1'],
            'items.*' => ['required', 'array:service_id,barber_id,date,time'],
            'items.*.service_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.barber_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'items.*.date' => ['required', 'date_format:Y-m-d'],
            'items.*.time' => ['required', 'date_format:H:i'],
        ]);
        foreach (['service_id', 'service_ids', 'barber_id', 'date', 'time'] as $legacyField) {
            if ($request->exists($legacyField)) {
                throw ValidationException::withMessages([
                    $legacyField => 'Não combine campos de reserva única com a lista de atendimentos.',
                ]);
            }
        }
        $data['phone'] = PhoneNumber::normalize($data['phone']);

        $appointments = DB::transaction(function () use ($data, $availability) {
            // Lock before snapshot reads so a waiting confirmation sees committed bookings.
            $barbers = User::whereIn('id', collect($data['items'])->pluck('barber_id')->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $shop = Barbershop::findOrFail($data['barbershop_id']);
            if (! $shop->is_open) {
                throw ValidationException::withMessages(['barbershop_id' => 'Esta unidade não está disponível.']);
            }
            $services = Service::withoutGlobalScope(BarbershopScope::class)
                ->whereIn('id', collect($data['items'])->pluck('service_id'))
                ->where('is_active', true)->where('duration', '>', 0)->get()->keyBy('id');
            $validated = [];
            foreach ($data['items'] as $index => $item) {
                $service = $services->get($item['service_id']);
                $barber = $barbers->get($item['barber_id']);
                if (! $service || ! $barber) {
                    throw ValidationException::withMessages([
                        "items.$index.service_id" => 'O serviço ou profissional não está mais disponível.',
                    ]);
                }
                try {
                    $slots = $availability->slots($shop, collect([$service]), $item['date'], $barber->id);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(
                        collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [
                            "items.$index.$field" => $messages,
                        ])->all()
                    );
                }
                if (! collect($slots)->contains(fn ($slot) => $slot['time'] === $item['time'])) {
                    throw ValidationException::withMessages([
                        "items.$index.time" => "O horário de {$service->name} não está mais disponível. Nenhuma reserva foi confirmada.",
                    ]);
                }
                $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $item['date'].' '.$item['time'], $shop->timezone)->utc();
                $end = $start->addMinutes($service->duration);
                foreach ($validated as $previous) {
                    if ($start->lt($previous['end']) && $end->gt($previous['start'])) {
                        throw ValidationException::withMessages([
                            "items.$index.time" => "O atendimento de {$service->name} coincide com outro serviço do pedido. Escolha um horário sem sobreposição.",
                        ]);
                    }
                }
                $validated[] = compact('service', 'barber', 'start', 'end');
            }

            $client = Client::create([
                'barbershop_id' => $shop->id,
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
            ]);

            return collect($validated)->map(function ($item) use ($client, $shop) {
                ['service' => $service, 'barber' => $barber, 'start' => $start, 'end' => $end] = $item;
                $appointment = Appointment::create([
                    'barbershop_id' => $shop->id, 'client_id' => $client->id,
                    'user_id' => $barber->id, 'service_id' => $service->id,
                    'start_time' => $start, 'end_time' => $end, 'status' => 'scheduled',
                ]);
                $appointment->services()->attach($service->id);
                $this->queueNotifications($appointment, $client, $shop, $barber, collect([$service]), $start->setTimezone($shop->timezone));

                return [
                    'id' => $appointment->id,
                    'service' => $service->only(['id', 'name', 'duration', 'price']),
                    'barber' => $barber->only(['id', 'name']),
                    'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
                    'timezone' => $shop->timezone,
                ];
            })->all();
        }, 3);

        return response()->json(['appointments' => $appointments], 201);
    }

    private function queueNotifications(
        Appointment $appointment,
        Client $client,
        Barbershop $shop,
        User $barber,
        Collection $services,
        CarbonImmutable $start,
    ): void {
        app(BookingNotifications::class)->confirmation(
            $appointment, $client, $shop, $barber, $services, $start,
        );
    }
}
