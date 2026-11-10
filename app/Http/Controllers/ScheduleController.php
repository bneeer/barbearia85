<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use App\Models\User;
use App\Rules\InternationalPhone;
use App\Services\BookingAvailability;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    private function appointmentVersion(Appointment $appointment): string
    {
        return hash('sha256', implode('|', [
            $appointment->start_time->toIso8601String(), $appointment->end_time->toIso8601String(),
            $appointment->status, $appointment->updated_at->toIso8601String(),
        ]));
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'barber']), 403);
    }

    private function shop(Request $request): Barbershop
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['barbershop_id' => ['required', 'integer']]);
        $shop = Barbershop::findOrFail($data['barbershop_id']);
        abort_unless($request->user()->isAdmin() || $shop->users()->whereKey($request->user()->id)->exists(), 403);

        return $shop;
    }

    private function barber(Request $request, Barbershop $shop, int $id): User
    {
        abort_unless($request->user()->isAdmin() || $request->user()->id === $id, 403);

        return $shop->users()->where('role', 'barber')->whereKey($id)->firstOrFail();
    }

    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return Inertia::render('Schedule/Index', [
            'csrfToken' => $request->session()->token(),
            'canManageAppointments' => $request->user()->isAdmin(),
            'barbershops' => Barbershop::query()
                ->when(! $request->user()->isAdmin(), fn ($query) => $query
                    ->whereHas('users', fn ($users) => $users->whereKey($request->user()->id)))
                ->with([
                    'photos',
                    'users' => fn ($query) => $query->where('role', 'barber')
                        ->when(! $request->user()->isAdmin(), fn ($query) => $query->whereKey($request->user()->id))
                        ->with(['services' => fn ($services) => $services->withoutGlobalScope(BarbershopScope::class)
                            ->where('is_active', true)->where('duration', '>', 0)])
                        ->orderBy('name'),
                    'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)
                        ->where('is_active', true)
                        ->where('duration', '>', 0)->orderBy('name'),
                    'products' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->orderBy('name'),
                ])->orderBy('name')->get()->map(fn ($shop) => [
                    'id' => $shop->id, 'name' => $shop->name, 'address' => $shop->address,
                    'timezone' => $shop->timezone, 'is_open' => $shop->is_open,
                    'today' => CarbonImmutable::now($shop->timezone)->format('Y-m-d'),
                    'photo' => $shop->photos->first()?->url,
                    'barbers' => $shop->users->map(fn ($barber) => [
                        ...$barber->only(['id', 'name', 'avatar']),
                        'service_ids' => $barber->services->modelKeys(),
                    ]),
                    'services' => $shop->services->map(fn ($service) => $service->only([
                        'id', 'name', 'duration', 'price',
                    ])),
                    'products' => $request->user()->isAdmin()
                        ? $shop->products->map(fn ($product) => $product->only(['id', 'name', 'brand', 'price']))
                        : [],
                ]),
        ]);
    }

    public function storeAppointment(Request $request, BookingAvailability $availability)
    {
        $shop = $this->shop($request);
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64', new InternationalPhone],
            'service_ids' => ['nullable', 'array', 'min:1', 'distinct'],
            'service_ids.*' => ['required', 'integer', 'distinct'],
            'service_id' => ['required_without:service_ids', 'integer'],
            'barber_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'selected_duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['phone'] = PhoneNumber::normalize($data['phone']);
        if (! $shop->is_open) {
            throw ValidationException::withMessages([
                'barbershop_id' => 'Esta unidade está fechada e não aceita agendamentos.',
            ]);
        }
        $serviceIds = $data['service_ids'] ?? [$data['service_id']];
        $availableServices = Service::withoutGlobalScope(BarbershopScope::class)
            ->where('barbershop_id', $shop->id)->where('is_active', true)
            ->where('duration', '>', 0)->whereIn('id', $serviceIds)->get()->keyBy('id');
        if ($availableServices->count() !== count($serviceIds)) {
            $key = isset($data['service_ids']) ? 'service_ids' : 'service_id';
            throw ValidationException::withMessages([
                $key => 'Este serviço não está disponível nesta unidade.',
            ]);
        }
        $services = collect($serviceIds)->map(fn ($serviceId) => $availableServices->get($serviceId));
        $duration = (int) $services->sum('duration');
        $barber = $this->barber($request, $shop, $data['barber_id']);
        $qualifiedServiceCount = $barber->services()->withoutGlobalScope(BarbershopScope::class)
            ->whereIn('services.id', $services->pluck('id')->all())->where('is_active', true)->count();
        if ($qualifiedServiceCount !== $services->count()) {
            $key = isset($data['service_ids']) ? 'service_ids' : 'service_id';
            throw ValidationException::withMessages([
                $key => 'Este barbeiro não está habilitado para prestar todos os serviços selecionados.',
            ]);
        }

        $appointment = DB::transaction(function () use ($data, $shop, $services, $duration, $barber, $availability) {
            User::whereKey($barber->id)->lockForUpdate()->firstOrFail();
            if (! collect($availability->slots($shop, $services, $data['date'], $barber->id))
                ->contains(fn ($slot) => $slot['time'] === $data['time'])) {
                throw ValidationException::withMessages([
                    'time' => 'Este horário não está mais disponível. Atualize os horários e escolha outro.',
                ]);
            }
            $start = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i', $data['date'].' '.$data['time'], $shop->timezone
            );
            if (isset($data['selected_duration']) && $data['selected_duration'] < $duration) {
                throw ValidationException::withMessages([
                    'selected_duration' => 'O intervalo selecionado precisa comportar a duração completa dos serviços.',
                ]);
            }
            $client = Client::create([
                'barbershop_id' => $shop->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'notes' => $data['notes'] ?? null,
            ]);
            $appointment = Appointment::create([
                'barbershop_id' => $shop->id,
                'client_id' => $client->id,
                'user_id' => $barber->id,
                'service_id' => $services->first()->id,
                'start_time' => $start->utc(),
                'end_time' => $start->addMinutes($duration)->utc(),
                'status' => 'scheduled',
                'notes' => $data['notes'] ?? null,
            ]);
            $appointment->services()->sync($services->pluck('id')->all());
            $serviceNames = $services->pluck('name')->implode(' + ');
            $servicePrices = (float) $services->sum(fn ($service) => (float) $service->price);
            foreach (['email', 'whatsapp'] as $channel) {
                Notification::create([
                    'appointment_id' => $appointment->id,
                    'client_id' => $client->id,
                    'type' => $channel,
                    'template' => 'booking_confirmation',
                    'status' => 'pending',
                    'payload' => [
                        'name' => $client->name,
                        'recipient' => $channel === 'email' ? $client->email : $client->phone,
                        'barbershop' => $shop->name,
                        'address' => $shop->address,
                        'phones' => $shop->phones,
                        'service' => $serviceNames,
                        'services' => $services->map(fn ($service) => [
                            'name' => $service->name, 'duration' => $service->duration, 'price' => $service->price,
                        ])->values()->all(),
                        'duration' => $duration,
                        'price' => $servicePrices,
                        'barber' => $barber->name,
                        'starts_at' => $start->toIso8601String(),
                        'timezone' => $shop->timezone,
                    ],
                ]);
            }

            return $appointment;
        }, 3);

        return response()->json(['id' => $appointment->id], 201);
    }

    public function events(Request $request)
    {
        $shop = $this->shop($request);
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'barber_id' => ['nullable', 'integer'],
        ]);
        $start = CarbonImmutable::parse($data['start'])->utc();
        $end = CarbonImmutable::parse($data['end'])->utc();
        if ($start->diffInDays($end) > 62) {
            throw ValidationException::withMessages(['end' => 'Consulte no máximo 62 dias por vez.']);
        }
        $barberId = $request->user()->isAdmin() ? ($data['barber_id'] ?? null) : $request->user()->id;
        if ($barberId !== null) {
            $this->barber($request, $shop, $barberId);
        }
        $barberIds = $shop->users()->where('role', 'barber')
            ->when($barberId !== null, fn ($query) => $query->whereKey($barberId))->pluck('users.id');
        $appointments = Appointment::withoutGlobalScope(BarbershopScope::class)
            ->where('barbershop_id', $shop->id)
            ->when($barberId !== null, fn ($query) => $query->where('user_id', $barberId))
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $end)->where('end_time', '>', $start)
            ->with([
                'client' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
                'barber',
                'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
                'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
                'checkout',
            ])->get()->map(fn ($appointment) => [
                'id' => 'appointment-'.$appointment->id,
                'title' => ($appointment->client?->name ?? 'Cliente').' · '.($appointment->barber?->name ?? 'Barbeiro'),
                'start' => $appointment->start_time->toIso8601String(),
                'end' => $appointment->end_time->toIso8601String(),
                'editable' => $request->user()->isAdmin() && $appointment->status === 'scheduled',
                'extendedProps' => [
                    'kind' => 'appointment', 'recordId' => $appointment->id,
                    'barberId' => $appointment->user_id, 'barberName' => $appointment->barber?->name,
                    'client' => $appointment->client?->only(['name', 'email', 'phone']),
                    'services' => $appointment->checkout
                        ? collect($appointment->checkout->services)->map(fn ($service) => [
                            'id' => $service['id'], 'name' => $service['name'], 'duration' => $service['duration'],
                            'price' => number_format($service['price_cents'] / 100, 2, '.', ''),
                        ])->values()
                        : ($appointment->services->isNotEmpty()
                            ? $appointment->services
                            : collect([$appointment->service])->filter())
                            ->map(fn ($service) => $service->only(['id', 'name', 'duration', 'price']))->values(),
                    'checkout' => $request->user()->isAdmin() && $appointment->checkout
                        ? $appointment->checkout->only([
                            'services', 'products', 'services_total_cents', 'products_total_cents',
                            'discount_cents', 'total_cents', 'payment_method', 'notes',
                        ]) : null,
                    'status' => $appointment->status, 'notes' => $appointment->notes,
                    'canCheckout' => $request->user()->isAdmin()
                        && $appointment->status === 'scheduled'
                        && $appointment->start_time->lte(CarbonImmutable::now()),
                    'version' => $this->appointmentVersion($appointment),
                ],
            ]);
        // Blocks belong to the professional and apply across every associated unit.
        $blocks = BlockedTime::whereIn('user_id', $barberIds)
            ->where('start_datetime', '<', $end)->where('end_datetime', '>', $start)
            ->with('user')->get()->map(fn ($block) => [
                'id' => 'block-'.$block->id,
                'title' => 'Bloqueio · '.$block->user->name,
                'start' => $block->start_datetime->toIso8601String(),
                'end' => $block->end_datetime->toIso8601String(),
                'editable' => true,
                'extendedProps' => [
                    'kind' => 'block', 'recordId' => $block->id, 'barberId' => $block->user_id,
                    'barberName' => $block->user->name, 'reason' => $block->reason,
                ],
            ]);

        return response()->json($appointments->concat($blocks)->values());
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function checkout(Request $request, int $id)
    {
        $shop = $this->shop($request);
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'services_total_cents' => ['required', 'integer', 'min:0'],
            'services' => ['required', 'array', 'min:1'],
            'services.*.id' => ['required', 'integer', 'distinct'],
            'services.*.price_cents' => ['required', 'integer', 'min:0'],
            'products' => ['present', 'array', 'max:100'],
            'products.*.id' => ['required', 'integer', 'distinct'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'products.*.price_cents' => ['required', 'integer', 'min:0'],
            'discount_cents' => ['required', 'integer', 'min:0'],
            'payment_method' => ['required', 'in:credit_card,debit_card,pix,cash'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $appointment = Appointment::withoutGlobalScope(BarbershopScope::class)
            ->where('barbershop_id', $shop->id)->findOrFail($id);

        DB::transaction(function () use ($request, $shop, $data, $appointment) {
            User::whereKey($appointment->user_id)->lockForUpdate()->firstOrFail();
            $appointment->refresh();
            abort_unless(hash_equals($this->appointmentVersion($appointment), $data['version']), 409, 'O agendamento foi alterado. Atualize o calendário.');
            if ($appointment->status !== 'scheduled' || $appointment->checkout()->exists()) {
                throw ValidationException::withMessages(['status' => 'Somente agendamentos pendentes podem ser finalizados.']);
            }
            if ($appointment->start_time->gt(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['start_time' => 'Não é possível finalizar um atendimento antes do horário de início do agendamento.']);
            }
            $services = $appointment->services()->withoutGlobalScope(BarbershopScope::class)->lockForUpdate()->get();
            if ($services->isEmpty()) {
                $services = collect([$appointment->service()->withoutGlobalScope(BarbershopScope::class)->withTrashed()->lockForUpdate()->firstOrFail()]);
            }
            $serviceItems = $services->map(fn ($service) => [
                'id' => $service->id, 'name' => $service->name, 'duration' => $service->duration,
                'price_cents' => $this->cents($service->price),
            ])->values();
            $servicesTotal = $serviceItems->sum('price_cents');
            $expectedServices = collect($data['services'])->keyBy('id');
            if ($servicesTotal !== (int) $data['services_total_cents']
                || $expectedServices->count() !== $serviceItems->count()
                || $serviceItems->contains(fn ($item) => ! $expectedServices->has($item['id'])
                    || (int) $expectedServices->get($item['id'])['price_cents'] !== $item['price_cents'])) {
                throw ValidationException::withMessages(['services' => 'Os preços dos serviços foram alterados. Atualize o calendário e confira os valores.']);
            }
            $products = Product::withoutGlobalScope(BarbershopScope::class)
                ->where('barbershop_id', $shop->id)->whereIn('id', collect($data['products'])->pluck('id'))
                ->lockForUpdate()->get()->keyBy('id');
            $productItems = collect($data['products'])->map(function ($item) use ($products) {
                $product = $products->get($item['id']);
                if (! $product || $this->cents($product->price) !== (int) $item['price_cents']) {
                    throw ValidationException::withMessages(['products' => 'Um produto não está disponível ou teve seu preço alterado. Recarregue a página e confira os valores.']);
                }

                return [
                    'id' => $product->id, 'name' => $product->name, 'brand' => $product->brand,
                    'quantity' => (int) $item['quantity'], 'price_cents' => $this->cents($product->price),
                ];
            });
            $productsTotal = $productItems->sum(fn ($item) => $item['quantity'] * $item['price_cents']);
            $subtotal = $servicesTotal + $productsTotal;
            if ($data['discount_cents'] > $subtotal) {
                throw ValidationException::withMessages(['discount_cents' => 'O desconto não pode superar o subtotal.']);
            }
            $appointment->checkout()->create([
                'created_by' => $request->user()->id,
                'services' => $serviceItems->all(), 'products' => $productItems->all(),
                'services_total_cents' => $servicesTotal, 'products_total_cents' => $productsTotal,
                'discount_cents' => $data['discount_cents'], 'total_cents' => $subtotal - $data['discount_cents'],
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
            ]);
            $appointment->update(['status' => 'completed']);
        }, 3);

        return response()->json(['message' => 'Atendimento finalizado.']);
    }

    private function interval(Request $request, Barbershop $shop): array
    {
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d\TH:i'],
            'end' => ['required', 'date_format:Y-m-d\TH:i', 'after:start'],
        ]);
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['start'], $shop->timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['end'], $shop->timezone);
        if ($start->format('Y-m-d\TH:i') !== $data['start'] || $end->format('Y-m-d\TH:i') !== $data['end'] || $end->lte($start)) {
            throw ValidationException::withMessages(['start' => 'Intervalo inválido.']);
        }

        return [$start, $end];
    }

    private function assertFree(int $barberId, CarbonImmutable $start, CarbonImmutable $end, ?int $appointmentId = null, ?int $blockId = null): void
    {
        if (Appointment::withoutGlobalScope(BarbershopScope::class)->where('user_id', $barberId)
            ->where('status', '!=', 'cancelled')
            ->when($appointmentId !== null, fn ($query) => $query->whereKeyNot($appointmentId))
            ->where('start_time', '<', $end->utc())->where('end_time', '>', $start->utc())->exists()) {
            throw ValidationException::withMessages(['start' => 'Há um agendamento neste intervalo, possivelmente em outra unidade. Reagende ou cancele antes de continuar.']);
        }
        if (BlockedTime::where('user_id', $barberId)
            ->when($blockId !== null, fn ($query) => $query->whereKeyNot($blockId))
            ->where('start_datetime', '<', $end->utc())->where('end_datetime', '>', $start->utc())->exists()) {
            throw ValidationException::withMessages(['start' => 'Este intervalo já contém um bloqueio da agenda.']);
        }
    }

    public function updateAppointment(Request $request, int $id)
    {
        $shop = $this->shop($request);
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'status' => ['required', 'in:scheduled,cancelled'],
            'version' => ['required', 'string', 'size:64'],
        ]);
        $appointment = Appointment::withoutGlobalScope(BarbershopScope::class)
            ->where('barbershop_id', $shop->id)->findOrFail($id);
        DB::transaction(function () use ($request, $shop, $data, $appointment) {
            User::whereKey($appointment->user_id)->lockForUpdate()->firstOrFail();
            $appointment->refresh();
            abort_unless(hash_equals($this->appointmentVersion($appointment), $data['version']), 409, 'O agendamento foi alterado. Atualize o calendário.');
            if ($appointment->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Somente agendamentos pendentes podem ser modificados.']);
            }
            if ($data['status'] === 'scheduled') {
                $barber = $this->barber($request, $shop, $appointment->user_id);
                [$start, $end] = $this->interval($request, $shop);
                $services = $appointment->services()->withoutGlobalScope(BarbershopScope::class)->get();
                if ($services->isEmpty()) {
                    $services = collect([$appointment->service()->withoutGlobalScope(BarbershopScope::class)
                        ->withTrashed()->firstOrFail()]);
                }
                $duration = (int) $services->sum('duration');
                $actualEnd = $start->addMinutes($duration);
                $hours = $shop->opening_hours[strtolower($start->format('l'))] ?? null;
                if ($start->lte(CarbonImmutable::now()) || ! $start->isSameDay($end)
                    || $end->getTimestamp() !== $actualEnd->getTimestamp()
                    || ! $shop->is_open || ! $hours || $hours['closed']
                    || $start->lt($start->startOfDay()->setTimeFromTimeString($hours['open']))
                    || $actualEnd->gt($start->startOfDay()->setTimeFromTimeString($hours['close']))) {
                    throw ValidationException::withMessages(['start' => 'Escolha um horário futuro dentro do expediente, com duração suficiente para os serviços.']);
                }
                $barber->load('workingHours');
                if ($barber->workingHours->isNotEmpty() && ! $barber->workingHours->contains(
                    fn ($hours) => (int) $hours->day_of_week === $start->dayOfWeek
                        && $start->gte($start->startOfDay()->setTimeFromTimeString($hours->start_time))
                        && $actualEnd->lte($start->startOfDay()->setTimeFromTimeString($hours->end_time))
                )) {
                    throw ValidationException::withMessages(['start' => 'Este horário está fora do expediente do barbeiro.']);
                }
                $this->assertFree($barber->id, $start, $actualEnd, appointmentId: $appointment->id);
                $appointment->start_time = $start->utc();
                $appointment->end_time = $actualEnd->utc();
            }
            $appointment->status = $data['status'];
            $appointment->save();
            $notifications = Notification::where('appointment_id', $appointment->id)->where('status', 'pending')->get();
            foreach ($notifications as $notification) {
                $payload = $notification->payload;
                $payload['starts_at'] = $appointment->start_time->setTimezone($shop->timezone)->toIso8601String();
                $payload['status'] = $appointment->status;
                $notification->update([
                    'payload' => $payload,
                    'template' => $appointment->status === 'cancelled' ? 'booking_cancellation' : 'booking_confirmation',
                ]);
            }
        }, 3);

        return response()->json(['message' => 'Agendamento atualizado.']);
    }

    public function storeBlock(Request $request)
    {
        return $this->saveBlock($request);
    }

    public function updateBlock(Request $request, int $id)
    {
        return $this->saveBlock($request, $id);
    }

    private function saveBlock(Request $request, ?int $id = null)
    {
        $shop = $this->shop($request);
        $data = $request->validate([
            'barber_ids' => ['required', 'array', 'min:1'],
            'barber_ids.*' => ['required', 'integer', 'distinct'],
            'reason' => ['required', 'string', 'max:255'],
            'full_day' => ['sometimes', 'boolean'],
            'date' => ['required_if:full_day,true', 'nullable', 'date_format:Y-m-d'],
        ]);
        $barbers = collect($data['barber_ids'])->sort()->map(fn ($barberId) => $this->barber($request, $shop, $barberId));
        $block = $id === null ? null : BlockedTime::findOrFail($id);
        if ($block) {
            $this->barber($request, $shop, $block->user_id);
            abort_unless($barbers->count() === 1 && $barbers->first()->id === $block->user_id, 422, 'Não é possível transferir bloqueios entre barbeiros.');
        }
        if ($data['full_day'] ?? false) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], $shop->timezone);
            $end = $start->addDay()->startOfDay();
        } else {
            [$start, $end] = $this->interval($request, $shop);
        }
        DB::transaction(function () use ($barbers, $block, $start, $end, $data, $shop) {
            foreach ($barbers as $barber) {
                User::whereKey($barber->id)->lockForUpdate()->firstOrFail();
            }
            $now = CarbonImmutable::now($shop->timezone);
            if ($data['full_day'] ?? false) {
                if ($start->lt($now->startOfDay())) {
                    throw ValidationException::withMessages(['date' => 'Não é possível bloquear dias passados.']);
                }
                if ($start->lt($now)) {
                    $start = $now;
                }
            } elseif ($start->lte($now)) {
                throw ValidationException::withMessages(['start' => 'Escolha um horário futuro para iniciar o bloqueio.']);
            }
            if ($block) {
                $block->refresh();
            }
            foreach ($barbers as $barber) {
                $this->assertFree($barber->id, $start, $end, blockId: $block?->id);
                $values = [
                    'user_id' => $barber->id, 'start_datetime' => $start->utc(),
                    'end_datetime' => $end->utc(), 'reason' => $data['reason'],
                ];
                $block ? $block->update($values) : BlockedTime::create($values);
            }
        }, 3);

        return response()->json(['message' => 'Bloqueio salvo.'], $id === null ? 201 : 200);
    }

    public function destroyBlock(Request $request, int $id)
    {
        $shop = $this->shop($request);
        $block = BlockedTime::findOrFail($id);
        $this->barber($request, $shop, $block->user_id);
        DB::transaction(function () use ($block) {
            User::whereKey($block->user_id)->lockForUpdate()->firstOrFail();
            $block->delete();
        }, 3);

        return response()->json(['message' => 'Bloqueio removido.']);
    }
}
