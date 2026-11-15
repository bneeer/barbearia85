<?php

namespace App\Services;

use App\Models\BarberSalaryRate;
use App\Models\Barbershop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialSummary
{
    public function adminSummary(Barbershop $shop, string $from, string $to): array
    {
        [$start, $end] = $this->utcBounds($shop, $from, $to);
        $checkouts = DB::table('appointment_checkouts')
            ->join('appointments', 'appointments.id', '=', 'appointment_checkouts.appointment_id')
            ->join('users', 'users.id', '=', 'appointments.user_id')
            ->where('appointments.barbershop_id', $shop->id)
            ->where('appointments.status', 'completed')
            ->whereBetween('appointment_checkouts.created_at', [$start, $end])
            ->select([
                'appointment_checkouts.total_cents',
                'appointment_checkouts.product_cost_cents',
                'appointment_checkouts.supply_cost_cents',
                'appointment_checkouts.commission_cents',
                'users.id as barber_id',
                'users.name as barber_name',
            ])->get();

        $expenses = DB::table('expenses')->where('barbershop_id', $shop->id)
            ->whereBetween('competence_date', [$from, $to]);
        $expenseTotal = (int) (clone $expenses)->sum('amount_cents');
        $expenseCount = (int) (clone $expenses)->count();
        $payments = DB::table('expense_payments')
            ->join('expenses', 'expenses.id', '=', 'expense_payments.expense_id')
            ->where('expenses.barbershop_id', $shop->id)
            ->whereBetween('expense_payments.paid_at', [$start, $end]);
        $paidExpenseTotal = (int) (clone $payments)->sum('expense_payments.amount_cents');

        $revenue = (int) $checkouts->sum('total_cents');
        $productCost = (int) $checkouts->whereNotNull('product_cost_cents')->sum('product_cost_cents');
        $supplyCost = (int) $checkouts->whereNotNull('supply_cost_cents')->sum('supply_cost_cents');
        $commission = (int) $checkouts->whereNotNull('commission_cents')->sum('commission_cents');
        $incomplete = $checkouts->filter(fn ($checkout) => $checkout->product_cost_cents === null
            || $checkout->supply_cost_cents === null
            || $checkout->commission_cents === null
        )->count();
        $salary = $this->salaryBaseForPeriod($shop, $from, $to);

        return [
            'revenue_cents' => $revenue,
            'expenses_cents' => $expenseTotal,
            'expense_count' => $expenseCount,
            'expense_payments_cents' => $paidExpenseTotal,
            'product_cost_cents' => $productCost,
            'supply_cost_cents' => $supplyCost,
            'commission_cents' => $commission,
            'salary_base_cents' => $salary,
            'estimated_profit_cents' => $incomplete > 0
                ? null
                : $revenue - $expenseTotal - $productCost - $supplyCost - $commission - $salary,
            'cash_flow_net_cents' => $revenue - $paidExpenseTotal,
            'checkout_count' => $checkouts->count(),
            'incomplete_checkout_count' => $incomplete,
            'barbers' => $checkouts->groupBy('barber_id')->map(fn ($rows) => [
                'id' => (int) $rows->first()->barber_id,
                'name' => $rows->first()->barber_name,
                'commission_cents' => (int) $rows->whereNotNull('commission_cents')->sum('commission_cents'),
                'incomplete_checkout_count' => $rows->filter(fn ($checkout) => $checkout->commission_cents === null)->count(),
            ])->sortBy('name')->values(),
        ];
    }

    public function barberCommissions(Barbershop $shop, int $barberId, string $from, string $to): array
    {
        [$start, $end] = $this->utcBounds($shop, $from, $to);
        $rows = DB::table('appointment_checkouts')
            ->join('appointments', 'appointments.id', '=', 'appointment_checkouts.appointment_id')
            ->where('appointments.barbershop_id', $shop->id)
            ->where('appointments.user_id', $barberId)
            ->where('appointments.status', 'completed')
            ->whereBetween('appointment_checkouts.created_at', [$start, $end])
            ->select('appointment_checkouts.commission_cents')->get();

        return [
            'commission_cents' => (int) $rows->whereNotNull('commission_cents')->sum('commission_cents'),
            'checkout_count' => $rows->count(),
            'incomplete_checkout_count' => $rows->whereNull('commission_cents')->count(),
        ];
    }

    private function salaryBaseForPeriod(Barbershop $shop, string $from, string $to): int
    {
        $start = CarbonImmutable::parse($from, $shop->timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $shop->timezone)->endOfDay();
        $rates = BarberSalaryRate::query()->where('barbershop_id', $shop->id)
            ->whereDate('effective_from', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $start->toDateString()))
            ->get();
        $total = 0;

        for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonth()) {
            $monthStart = $month->startOfMonth();
            $monthEnd = $month->endOfMonth();
            $rangeStart = $start->gt($monthStart) ? $start : $monthStart;
            $rangeEnd = $end->lt($monthEnd) ? $end : $monthEnd;
            $daysInMonth = $month->daysInMonth;

            foreach ($rates as $rate) {
                $rateStart = CarbonImmutable::parse($rate->effective_from, $shop->timezone)->startOfDay();
                $rateEnd = $rate->effective_until
                    ? CarbonImmutable::parse($rate->effective_until, $shop->timezone)->endOfDay()
                    : $monthEnd;
                $overlapStart = $rangeStart->gt($rateStart) ? $rangeStart : $rateStart;
                $overlapEnd = $rangeEnd->lt($rateEnd) ? $rangeEnd : $rateEnd;
                if ($overlapStart->gt($overlapEnd)) {
                    continue;
                }

                $firstCalendarDay = CarbonImmutable::parse($overlapStart->toDateString(), 'UTC');
                $lastCalendarDay = CarbonImmutable::parse($overlapEnd->toDateString(), 'UTC');
                $days = (int) $firstCalendarDay->diffInDays($lastCalendarDay) + 1;
                $total += intdiv($rate->salary_base_cents * $days + intdiv($daysInMonth, 2), $daysInMonth);
            }
        }

        return $total;
    }

    private function utcBounds(Barbershop $shop, string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from, $shop->timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $shop->timezone)->endOfDay()->utc(),
        ];
    }
}
