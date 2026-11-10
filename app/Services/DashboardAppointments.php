<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Scopes\BarbershopScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardAppointments
{
    public function query(User $viewer): Builder
    {
        abort_unless($viewer->isBarber(), 403);

        return Appointment::withoutGlobalScope(BarbershopScope::class)
            ->when(! $viewer->isAdmin(), fn (Builder $query) => $query->where('user_id', $viewer->id));
    }

    public function inPeriod(Builder $query, string $period, CarbonImmutable $reference): Builder
    {
        $local = $reference->setTimezone(config('app.display_timezone'));
        $start = match ($period) {
            'day' => $local->startOfDay(),
            'week' => $local->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $local->startOfMonth(),
        };
        $end = match ($period) {
            'day' => $start->addDay(),
            'week' => $start->addWeek(),
            'month' => $start->addMonth(),
        };

        return $query->where('start_time', '>=', $start->utc())->where('start_time', '<', $end->utc());
    }

    public function indicators(CarbonImmutable $reference, User $viewer): array
    {
        $indicators = [];
        $barberCounts = collect();
        foreach (['day', 'week', 'month'] as $period) {
            $query = $this->inPeriod($this->query($viewer), $period, $reference)
                ->whereIn('status', ['completed', 'scheduled']);
            if ($viewer->isAdmin()) {
                $amounts = $this->withExpectedAmount($query->select('appointments.id', 'user_id', 'status'))
                    ->selectSub(DB::table('appointment_checkouts')->select('total_cents')
                        ->whereColumn('appointment_id', 'appointments.id'), 'checkout_cents');
                $counts = DB::query()->fromSub($amounts, 'amounts')
                    ->selectRaw('user_id, status, count(*) as aggregate,
                        sum(checkout_cents) as completed_cents, sum(expected_cents) as scheduled_cents,
                        sum(case when checkout_cents is null then 1 else 0 end) as missing_completed,
                        sum(case when expected_cents is null then 1 else 0 end) as missing_scheduled')
                    ->groupBy('user_id', 'status')->get();
            } else {
                $counts = $query->selectRaw('user_id, status, count(*) as aggregate')
                    ->groupBy('user_id', 'status')->get();
            }
            if ($viewer->isAdmin()) {
                $barberCounts->put($period, $counts->groupBy('user_id'));
            }
            $completed = (int) $counts->where('status', 'completed')->sum('aggregate');
            $scheduled = (int) $counts->where('status', 'scheduled')->sum('aggregate');
            $indicators[] = [
                'period' => $period, 'completed' => $completed, 'scheduled' => $scheduled,
                'total' => $completed + $scheduled,
                ...($viewer->isAdmin() ? ['financials' => $this->financials($counts)] : []),
            ];
        }

        if (! $viewer->isAdmin()) {
            return ['reference_at' => $reference->toIso8601String(), 'periods' => $indicators, 'barbers' => []];
        }

        $barberIds = $barberCounts->flatMap(fn ($counts) => $counts->keys())->unique()->values();
        $barbers = User::query()->where(fn (Builder $users) => $users->where('role', 'barber')->orWhereIn('id', $barberIds))
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'photo_path'])
            ->map(fn (User $barber) => [
                'id' => $barber->id, 'name' => $barber->name, 'avatar' => $barber->avatar,
                'periods' => $barberCounts->map(function ($counts, $period) use ($barber) {
                    $statuses = $counts->get($barber->id, collect())->pluck('aggregate', 'status');
                    $completed = (int) ($statuses['completed'] ?? 0);
                    $scheduled = (int) ($statuses['scheduled'] ?? 0);

                    return [
                        'period' => $period, 'completed' => $completed, 'scheduled' => $scheduled,
                        'total' => $completed + $scheduled,
                        'financials' => $this->financials($counts->get($barber->id, collect())),
                    ];
                })->values()->all(),
            ])->all();

        return ['reference_at' => $reference->toIso8601String(), 'periods' => $indicators, 'barbers' => $barbers];
    }

    public function rangeSummary(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $barbershopId,
        User $viewer,
    ): array {
        $timezone = config('app.display_timezone');
        $start = $from->setTimezone($timezone)->startOfDay();
        $end = $to->setTimezone($timezone)->startOfDay()->addDay();
        $days = (int) $start->diffInDays($end);
        $previousStart = $start->subDays($days);

        $current = $this->rangeMetrics($start, $end, $barbershopId, $viewer);
        $previous = $this->rangeMetrics($previousStart, $start, $barbershopId, $viewer);
        $current['capacity'] = $this->capacityMetrics($start, $end, $barbershopId, $viewer);
        $previous['capacity'] = $this->capacityMetrics($previousStart, $start, $barbershopId, $viewer);
        $barbers = [];
        if ($viewer->isAdmin()) {
            $barberIds = collect($current['barbers'])->keys()->merge(collect($previous['barbers'])->keys())->unique();
            $barbers = User::query()
                ->where(fn (Builder $query) => $query->where('role', 'barber')->orWhereIn('id', $barberIds))
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'photo_path'])
                ->map(fn (User $barber) => [
                    'id' => $barber->id,
                    'name' => $barber->name,
                    'avatar' => $barber->avatar,
                    'current' => $current['barbers'][$barber->id] ?? $this->emptyBarberMetrics(),
                    'previous' => $previous['barbers'][$barber->id] ?? $this->emptyBarberMetrics(),
                ])->all();
        }

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->subDay()->format('Y-m-d'),
            'previous_from' => $previousStart->format('Y-m-d'),
            'previous_to' => $start->subDay()->format('Y-m-d'),
            'current' => $current,
            'previous' => $previous,
            'barbers' => $barbers,
        ];
    }

    private function rangeMetrics(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $barbershopId,
        User $viewer,
    ): array {
        $counts = $this->query($viewer)
            ->where('start_time', '>=', $start->utc())
            ->where('start_time', '<', $end->utc())
            ->when($barbershopId, fn (Builder $query) => $query->where('barbershop_id', $barbershopId))
            ->selectRaw('user_id, status, count(*) as aggregate')
            ->groupBy('user_id', 'status')->get();
        $statuses = $counts->groupBy('status')
            ->map(fn ($items) => (int) $items->sum('aggregate'));
        $completed = (int) ($statuses['completed'] ?? 0);
        $scheduled = (int) ($statuses['scheduled'] ?? 0);

        $result = [
            'completed' => $completed,
            'scheduled' => $scheduled,
            'cancelled' => (int) ($statuses['cancelled'] ?? 0),
            'no_show' => (int) ($statuses['no_show'] ?? 0),
            'total' => $completed + $scheduled,
            'retention' => $this->retentionMetrics($start, $end, $barbershopId, $viewer),
        ];

        if ($viewer->isAdmin()) {
            $result['financials'] = $this->checkoutMetrics($start, $end, $barbershopId);
            $financialsByBarber = $this->checkoutMetricsByBarber($start, $end, $barbershopId);
            $result['barbers'] = $counts->groupBy('user_id')->map(function ($items, $userId) use ($financialsByBarber) {
                $byStatus = $items->pluck('aggregate', 'status');

                return [
                    'completed' => (int) ($byStatus['completed'] ?? 0),
                    'scheduled' => (int) ($byStatus['scheduled'] ?? 0),
                    'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                    'no_show' => (int) ($byStatus['no_show'] ?? 0),
                    'financials' => $financialsByBarber->get((int) $userId, $this->emptyCheckoutMetrics()),
                ];
            });
        }

        return $result;
    }

    private function retentionMetrics(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $barbershopId,
        User $viewer,
    ): array {
        $currentClients = DB::table('appointments')
            ->select('client_id')
            ->whereNull('deleted_at')->where('status', 'completed')
            ->where('start_time', '>=', $start->utc())->where('start_time', '<', $end->utc())
            ->when(! $viewer->isAdmin(), fn ($query) => $query->where('user_id', $viewer->id))
            ->when($barbershopId, fn ($query) => $query->where('barbershop_id', $barbershopId));
        $unique = (int) (clone $currentClients)->distinct()->count('client_id');
        $returning = (int) DB::table('appointments as prior')
            ->whereNull('prior.deleted_at')->where('prior.status', 'completed')
            ->where('prior.start_time', '<', $start->utc())
            ->whereIn('prior.client_id', (clone $currentClients)->select('client_id')->distinct())
            ->when(! $viewer->isAdmin(), fn ($query) => $query->where('prior.user_id', $viewer->id))
            ->when($barbershopId, fn ($query) => $query->where('prior.barbershop_id', $barbershopId))
            ->distinct()->count('prior.client_id');

        return [
            'unique_clients' => $unique,
            'new_clients' => $unique - $returning,
            'returning_clients' => $returning,
        ];
    }

    private function checkoutMetrics(CarbonImmutable $start, CarbonImmutable $end, ?int $barbershopId): array
    {
        $query = DB::table('appointment_checkouts as checkout')
            ->join('appointments', 'appointments.id', '=', 'checkout.appointment_id')
            ->whereNull('appointments.deleted_at')->where('appointments.status', 'completed')
            ->where('appointments.start_time', '>=', $start->utc())
            ->where('appointments.start_time', '<', $end->utc())
            ->when($barbershopId, fn ($query) => $query->where('appointments.barbershop_id', $barbershopId));
        $totals = (clone $query)->selectRaw(
            'count(*) as checkout_count, round(avg(total_cents), 0) as average_ticket_cents, sum(discount_cents) as discount_cents'
        )->first();
        $methods = $query->selectRaw(
            'payment_method, count(*) as count, sum(total_cents) as total_cents'
        )->groupBy('payment_method')->get()->keyBy('payment_method')
            ->map(fn ($item) => ['count' => (int) $item->count, 'total_cents' => (int) $item->total_cents]);

        return [
            'checkout_count' => (int) $totals->checkout_count,
            'average_ticket_cents' => (int) ($totals->average_ticket_cents ?? 0),
            'discount_cents' => (int) ($totals->discount_cents ?? 0),
            'payment_methods' => $methods,
        ];
    }

    private function checkoutMetricsByBarber(CarbonImmutable $start, CarbonImmutable $end, ?int $barbershopId): Collection
    {
        return DB::table('appointment_checkouts as checkout')
            ->join('appointments', 'appointments.id', '=', 'checkout.appointment_id')
            ->whereNull('appointments.deleted_at')->where('appointments.status', 'completed')
            ->where('appointments.start_time', '>=', $start->utc())
            ->where('appointments.start_time', '<', $end->utc())
            ->when($barbershopId, fn ($query) => $query->where('appointments.barbershop_id', $barbershopId))
            ->selectRaw('appointments.user_id, count(*) as checkout_count, round(avg(total_cents), 0) as average_ticket_cents, sum(discount_cents) as discount_cents')
            ->groupBy('appointments.user_id')->get()->keyBy('user_id')
            ->map(fn ($item) => [
                'checkout_count' => (int) $item->checkout_count,
                'average_ticket_cents' => (int) ($item->average_ticket_cents ?? 0),
                'discount_cents' => (int) ($item->discount_cents ?? 0),
                'payment_methods' => [],
            ]);
    }

    private function emptyCheckoutMetrics(): array
    {
        return [
            'checkout_count' => 0,
            'average_ticket_cents' => 0,
            'discount_cents' => 0,
            'payment_methods' => [],
        ];
    }

    private function emptyBarberMetrics(): array
    {
        return [
            'completed' => 0,
            'scheduled' => 0,
            'cancelled' => 0,
            'no_show' => 0,
            'financials' => $this->emptyCheckoutMetrics(),
        ];
    }

    private function capacityMetrics(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $barbershopId,
        User $viewer,
    ): ?array {
        if ($barbershopId !== null) {
            return null;
        }

        $shops = Barbershop::query()
            ->when(! $viewer->isAdmin(), fn (Builder $query) => $query->where(
                fn (Builder $shops) => $shops->whereHas('users', fn (Builder $users) => $users->whereKey($viewer->id))
                    ->orWhere('barbershops.id', $viewer->barbershop_id),
            ))
            ->with([
                'users' => fn ($query) => $query->where('users.role', 'barber')
                    ->when(! $viewer->isAdmin(), fn ($query) => $query->whereKey($viewer->id))
                    ->with('workingHours'),
            ])->get();
        $appointments = $this->query($viewer)
            ->whereHas('barber', fn (Builder $barber) => $barber->where('role', 'barber'))
            ->whereIn('status', ['completed', 'scheduled'])
            ->where('start_time', '<', $end->utc())->where('end_time', '>', $start->utc())
            ->with('barber.workingHours')->get();
        $ids = $appointments->pluck('user_id')->merge(
            $shops->flatMap(fn ($shop) => $shop->users->pluck('id'))
        )->unique()->values();
        if (! $viewer->isAdmin()) {
            $ids = collect([$viewer->id]);
        }

        $blocks = BlockedTime::query()->whereIn('user_id', $ids)
            ->where('start_datetime', '<', $end->utc())->where('end_datetime', '>', $start->utc())->get()
            ->groupBy('user_id');
        $appointmentsByBarber = $appointments->groupBy('user_id');
        $results = [];
        $availableTotal = 0;
        $occupiedTotal = 0;

        foreach ($ids as $id) {
            $barber = $appointmentsByBarber->get($id)?->first()?->barber
                ?? ($viewer->id === $id ? $viewer->loadMissing('workingHours') : null)
                ?? $shops->flatMap(fn ($shop) => $shop->users)->firstWhere('id', $id);
            if (! $barber) {
                continue;
            }

            $intervals = $this->workingIntervals($barber, $shops, $start, $end);
            $intervals = $this->subtractIntervals(
                $intervals,
                ($blocks->get($id) ?? collect())->map(fn ($block) => [
                    'start' => $block->start_datetime->timestamp,
                    'end' => $block->end_datetime->timestamp,
                ])->all(),
            );
            $booked = ($appointmentsByBarber->get($id) ?? collect())->map(fn ($appointment) => [
                'start' => $appointment->start_time->timestamp,
                'end' => $appointment->end_time->timestamp,
            ])->all();
            $available = $this->intervalMinutes($intervals);
            $occupied = $this->intersectionMinutes($intervals, $booked);
            $availableTotal += $available;
            $occupiedTotal += $occupied;
            $results[] = [
                'id' => $id,
                'name' => $barber->name,
                'available_minutes' => $available,
                'occupied_minutes' => $occupied,
                'free_minutes' => max(0, $available - $occupied),
                'occupancy_percent' => $available > 0 ? round($occupied / $available * 100, 1) : 0,
            ];
        }

        return [
            'available_minutes' => $availableTotal,
            'occupied_minutes' => $occupiedTotal,
            'free_minutes' => max(0, $availableTotal - $occupiedTotal),
            'occupancy_percent' => $availableTotal > 0 ? round($occupiedTotal / $availableTotal * 100, 1) : 0,
            'barbers' => collect($results)->sortBy('name')->values(),
        ];
    }

    private function workingIntervals(
        User $barber,
        Collection $shops,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $shopIds = $shops
            ->filter(fn ($shop) => $shop->users->contains(fn (User $user) => $user->id === $barber->id))
            ->pluck('id')->all();
        if ($barber->barbershop_id) {
            $shopIds[] = $barber->barbershop_id;
        }
        $intervals = [];

        foreach ($shops->whereIn('id', array_unique($shopIds)) as $shop) {
            if (! $shop->is_open) {
                continue;
            }
            $localStart = $start->setTimezone($shop->timezone)->startOfDay();
            $localEnd = $end->setTimezone($shop->timezone)->startOfDay();

            for ($day = $localStart; $day->lte($localEnd); $day = $day->addDay()) {
                $opening = $shop->opening_hours[strtolower($day->format('l'))] ?? null;
                if (! $opening || ($opening['closed'] ?? false)) {
                    continue;
                }
                $open = $day->setTimeFromTimeString($opening['open'])->utc()->timestamp;
                $close = $day->setTimeFromTimeString($opening['close'])->utc()->timestamp;
                $hasCustomWorkingHours = $barber->workingHours->isNotEmpty();
                $hours = $barber->workingHours->where('day_of_week', $day->dayOfWeek);
                $segments = ! $hasCustomWorkingHours
                    ? [['start' => $open, 'end' => $close]]
                    : $hours->map(function ($hour) use ($day, $open, $close) {
                        $workStart = $day->setTimeFromTimeString($hour->start_time)->utc()->timestamp;
                        $workEnd = $day->setTimeFromTimeString($hour->end_time)->utc()->timestamp;

                        return ['start' => max($open, $workStart), 'end' => min($close, $workEnd)];
                    })->filter(fn ($item) => $item['start'] < $item['end'])->values()->all();
                foreach ($segments as $segment) {
                    $left = max($segment['start'], $start->utc()->timestamp);
                    $right = min($segment['end'], $end->utc()->timestamp);
                    if ($left < $right) {
                        $intervals[] = ['start' => $left, 'end' => $right];
                    }
                }
            }
        }

        return $this->mergeIntervals($intervals);
    }

    private function subtractIntervals(array $source, array $blocks): array
    {
        $remaining = $this->mergeIntervals($source);
        foreach ($this->mergeIntervals($blocks) as $block) {
            $next = [];
            foreach ($remaining as $interval) {
                if ($block['end'] <= $interval['start'] || $block['start'] >= $interval['end']) {
                    $next[] = $interval;

                    continue;
                }
                if ($block['start'] > $interval['start']) {
                    $next[] = ['start' => $interval['start'], 'end' => $block['start']];
                }
                if ($block['end'] < $interval['end']) {
                    $next[] = ['start' => $block['end'], 'end' => $interval['end']];
                }
            }
            $remaining = $next;
        }

        return $remaining;
    }

    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($intervals as $interval) {
            if ($interval['start'] >= $interval['end']) {
                continue;
            }
            $last = array_key_last($merged);
            if ($last !== null && $interval['start'] <= $merged[$last]['end']) {
                $merged[$last]['end'] = max($merged[$last]['end'], $interval['end']);
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    private function intervalMinutes(array $intervals): int
    {
        return (int) round(array_sum(array_map(fn ($interval) => $interval['end'] - $interval['start'], $intervals)) / 60);
    }

    private function intersectionMinutes(array $available, array $booked): int
    {
        $occupied = [];
        foreach ($this->mergeIntervals($booked) as $appointment) {
            foreach ($available as $interval) {
                $start = max($interval['start'], $appointment['start']);
                $end = min($interval['end'], $appointment['end']);
                if ($start < $end) {
                    $occupied[] = ['start' => $start, 'end' => $end];
                }
            }
        }

        return $this->intervalMinutes($this->mergeIntervals($occupied));
    }

    public function withExpectedAmount(Builder $query): Builder
    {
        return $query->selectRaw('
            case when exists (
                select 1 from appointment_service where appointment_id = appointments.id
            ) then (
                select case when count(*) = count(services.price)
                    then sum(round(services.price * 100, 0)) else null end
                from appointment_service
                left join services on services.id = appointment_service.service_id
                where appointment_service.appointment_id = appointments.id
            ) else (
                select round(services.price * 100, 0) from services
                where services.id = appointments.service_id
            ) end as expected_cents
        ');
    }

    private function financials(Collection $counts): array
    {
        $completed = $counts->where('status', 'completed');
        $scheduled = $counts->where('status', 'scheduled');
        $completedCents = (int) $completed->sum('completed_cents');
        $scheduledCents = (int) $scheduled->sum('scheduled_cents');

        return [
            'completed_cents' => $completedCents,
            'scheduled_cents' => $scheduledCents,
            'total_cents' => $completedCents + $scheduledCents,
            'missing_completed' => (int) $completed->sum('missing_completed'),
            'missing_scheduled' => (int) $scheduled->sum('missing_scheduled'),
        ];
    }

    public function forService(Builder $query, int $serviceId): Builder
    {
        return $query->where(function (Builder $services) use ($serviceId) {
            $services->whereHas('checkout', function (Builder $checkout) use ($serviceId) {
                if ($checkout->getConnection()->getDriverName() === 'sqlite') {
                    $checkout->whereRaw("exists (select 1 from json_each(appointment_checkouts.services) as item where json_extract(item.value, '$.id') = ?)", [$serviceId]);
                } else {
                    $checkout->whereJsonContains('services', ['id' => $serviceId]);
                }
            })
                ->orWhere(fn (Builder $legacy) => $legacy->whereDoesntHave('checkout')
                    ->where(fn (Builder $items) => $items
                        ->whereHas('services', fn (Builder $service) => $service->withoutGlobalScope(BarbershopScope::class)->whereKey($serviceId))
                        ->orWhere(fn (Builder $single) => $single->whereDoesntHave('services', fn (Builder $service) => $service->withoutGlobalScope(BarbershopScope::class))
                            ->where('service_id', $serviceId))));
        });
    }

    public function servicesTimeline(CarbonImmutable $reference, User $viewer): array
    {
        $timezone = config('app.display_timezone');
        $end = $reference->setTimezone($timezone)->startOfDay()->addDay();
        $start = $end->subDays(60);
        $dates = [];
        for ($date = $start; $date->lt($end); $date = $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
        }
        $futureDates = [];
        for ($date = $end; $date->lt($end->addDays(7)); $date = $date->addDay()) {
            $futureDates[] = $date->format('Y-m-d');
        }
        $series = [];
        $appointments = $this->query($viewer)->where(function (Builder $query) use ($start, $end) {
            $query->where(fn (Builder $history) => $history->where('status', 'completed')
                ->where('start_time', '>=', $start->utc())->where('start_time', '<', $end->utc()))
                ->orWhere(fn (Builder $future) => $future->where('status', 'scheduled')
                    ->where('start_time', '>=', $end->utc())->where('start_time', '<', $end->addDays(7)->utc()));
        })
            ->with([
                'checkout',
                'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
                'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
            ])->lazyById(500);
        foreach ($appointments as $appointment) {
            $day = $appointment->start_time->setTimezone($timezone)->format('Y-m-d');
            $items = $appointment->checkout
                ? collect($appointment->checkout->services)
                : ($appointment->services->isNotEmpty() ? $appointment->services : collect([$appointment->service])->filter())
                    ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name]);
            foreach ($items->unique('id') as $item) {
                $id = $item['id'];
                if (! isset($series[$id])) {
                    $series[$id] = [
                        'id' => $id, 'name' => $item['name'], 'counts' => array_fill_keys($dates, 0),
                        'scheduled_counts' => array_fill_keys($futureDates, 0),
                    ];
                }
                if ($appointment->checkout) {
                    $series[$id]['name'] = $item['name'];
                }
                $field = $appointment->status === 'scheduled' ? 'scheduled_counts' : 'counts';
                $series[$id][$field][$day]++;
            }
        }

        return [
            'timezone' => $timezone, 'dates' => $dates, 'future_dates' => $futureDates,
            'series' => collect($series)->sortBy('name')->map(fn ($service) => [
                'id' => $service['id'], 'name' => $service['name'], 'counts' => array_values($service['counts']),
                'scheduled_counts' => array_values($service['scheduled_counts']),
            ])->values()->all(),
        ];
    }

    public function servicesTimelineForRange(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $barbershopId,
        User $viewer,
    ): array {
        $timezone = config('app.display_timezone');
        $dates = [];
        for ($date = $start->setTimezone($timezone)->startOfDay(); $date->lte($end->setTimezone($timezone)->startOfDay()); $date = $date->addDay()) {
            $dates[] = $date->format('Y-m-d');
        }
        $series = [];
        $appointments = $this->query($viewer)
            ->whereIn('status', ['completed', 'scheduled'])
            ->where('start_time', '>=', $start->setTimezone($timezone)->startOfDay()->utc())
            ->where('start_time', '<', $end->setTimezone($timezone)->startOfDay()->addDay()->utc())
            ->when($barbershopId, fn (Builder $query) => $query->where('barbershop_id', $barbershopId))
            ->with([
                'checkout',
                'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
                'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
            ])->lazyById(500);

        foreach ($appointments as $appointment) {
            $day = $appointment->start_time->setTimezone($timezone)->format('Y-m-d');
            $items = $appointment->checkout
                ? collect($appointment->checkout->services)
                : ($appointment->services->isNotEmpty() ? $appointment->services : collect([$appointment->service])->filter())
                    ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name]);
            foreach ($items->unique('id') as $item) {
                $id = $item['id'];
                if (! isset($series[$id])) {
                    $series[$id] = [
                        'id' => $id,
                        'name' => $item['name'],
                        'counts' => array_fill_keys($dates, 0),
                        'scheduled_counts' => array_fill_keys($dates, 0),
                        ...($viewer->isAdmin() ? ['revenue_cents' => 0] : []),
                    ];
                }
                if ($appointment->checkout) {
                    $series[$id]['name'] = $item['name'];
                }
                $field = $appointment->status === 'scheduled' ? 'scheduled_counts' : 'counts';
                $series[$id][$field][$day]++;
                if ($viewer->isAdmin() && $appointment->status === 'completed') {
                    if (! $appointment->checkout) {
                        $series[$id]['revenue_cents'] = null;
                    } elseif ($series[$id]['revenue_cents'] !== null) {
                        $series[$id]['revenue_cents'] += $item['price_cents'];
                    }
                }
            }
        }

        return [
            'timezone' => $timezone,
            'dates' => $dates,
            'future_dates' => [],
            'series' => collect($series)->sortBy('name')->map(fn ($service) => [
                'id' => $service['id'],
                'name' => $service['name'],
                'counts' => array_values($service['counts']),
                'scheduled_counts' => array_values($service['scheduled_counts']),
                ...($viewer->isAdmin() ? ['revenue_cents' => $service['revenue_cents']] : []),
            ])->values()->all(),
        ];
    }
}
