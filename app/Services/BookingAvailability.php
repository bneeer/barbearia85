<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BookingAvailability
{
    public function barbers(Barbershop $shop, Collection $services, ?int $barberId = null): Collection
    {
        $serviceIds = $services->pluck('id')->all();

        return $shop->users()->where('role', 'barber')
            ->whereHas('services', fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)
                ->whereIn('services.id', $serviceIds)->where('is_active', true), '=', count($serviceIds))
            ->when($barberId !== null, fn ($query) => $query->where('users.id', $barberId))
            ->orderBy('name')->orderBy('users.id')->get(['users.id', 'users.name', 'users.photo_path']);
    }

    public function slots(Barbershop $shop, Collection $services, string $date, ?int $barberId = null): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $shop->timezone);
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        if ($day->lt($today) || $day->gt($today->addDays(45))) {
            throw ValidationException::withMessages(['date' => 'Escolha uma data entre hoje e os próximos 45 dias.']);
        }

        $hours = $shop->opening_hours[strtolower($day->format('l'))] ?? null;
        $duration = (int) $services->sum('duration');
        if (! $shop->is_open || ! $hours || $hours['closed'] || $services->isEmpty() || $duration <= 0
            || $services->contains(fn (Service $service) => ! $service->is_active || $service->duration <= 0)) {
            return [];
        }

        $barbers = $this->barbers($shop, $services, $barberId);
        $barbers->load('workingHours');
        $start = $day->setTimeFromTimeString($hours['open']);
        $close = $day->setTimeFromTimeString($hours['close']);
        $utcStart = $day->utc();
        $utcEnd = $day->addDay()->utc();
        // A barber cannot serve two units at the same time.
        $appointments = Appointment::withoutGlobalScope(BarbershopScope::class)
            ->whereIn('user_id', $barbers->modelKeys())->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $utcEnd)->where('end_time', '>', $utcStart)->get();
        $blocks = BlockedTime::whereIn('user_id', $barbers->modelKeys())
            ->where('start_datetime', '<', $utcEnd)->where('end_datetime', '>', $utcStart)->get();
        $slots = [];

        for ($time = $start; $time->lt($close); $time = $time->addMinutes(40)) {
            $end = $time->addMinutes($duration);
            if ($end->gt($close) || $time->lte(CarbonImmutable::now($shop->timezone))) {
                continue;
            }
            $available = $barbers->filter(function ($barber) use ($day, $time, $end, $appointments, $blocks) {
                if ($barber->workingHours->isNotEmpty() && ! $barber->workingHours->contains(
                    fn ($hours) => (int) $hours->day_of_week === $day->dayOfWeek
                        && $time->gte($day->setTimeFromTimeString($hours->start_time))
                        && $end->lte($day->setTimeFromTimeString($hours->end_time))
                )) {
                    return false;
                }

                return ! $appointments->contains(fn ($booking) => $booking->user_id === $barber->id
                    && $booking->start_time->lt($end) && $booking->end_time->gt($time))
                    && ! $blocks->contains(fn ($block) => $block->user_id === $barber->id
                        && $block->start_datetime->lt($end) && $block->end_datetime->gt($time));
            });
            if ($available->isNotEmpty()) {
                $slots[] = [
                    'time' => $time->format('H:i'),
                    'barber_ids' => $available->values()->modelKeys(),
                ];
            }
        }

        return $slots;
    }
}
