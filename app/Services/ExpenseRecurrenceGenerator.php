<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ExpenseRecurrenceGenerator
{
    public function generateCurrentMonth(?CarbonImmutable $today = null): int
    {
        $created = 0;

        ExpenseRecurrence::query()
            ->where('active', true)
            ->with('barbershop')
            ->orderBy('id')->each(function (ExpenseRecurrence $recurrence) use ($today, &$created) {
                $localToday = ($today ?? CarbonImmutable::now($recurrence->barbershop->timezone))
                    ->setTimezone($recurrence->barbershop->timezone);
                $monthStart = $localToday->startOfMonth();
                $monthEnd = $localToday->endOfMonth();
                if ($recurrence->starts_on->gt($localToday)
                    || ($recurrence->ends_on && $recurrence->ends_on->lt($monthStart))) {
                    return;
                }

                $dueDate = $monthStart->setDay(min($recurrence->due_day, $monthEnd->day));
                if ($dueDate->lt($recurrence->starts_on)) {
                    $dueDate = $recurrence->starts_on;
                }
                if ($recurrence->ends_on && $dueDate->gt($recurrence->ends_on)) {
                    return;
                }

                DB::transaction(function () use ($recurrence, $monthStart, $dueDate, &$created) {
                    $expense = Expense::query()->firstOrCreate(
                        [
                            'recurrence_id' => $recurrence->id,
                            'competence_date' => $monthStart->toDateTimeString(),
                        ],
                        [
                            'barbershop_id' => $recurrence->barbershop_id,
                            'created_by' => $recurrence->created_by,
                            'title' => $recurrence->title,
                            'category' => $recurrence->category,
                            'amount_cents' => $recurrence->amount_cents,
                            'due_date' => $dueDate->toDateString(),
                        ],
                    );
                    if ($expense->wasRecentlyCreated) {
                        $created++;
                    }
                }, 3);
            });

        return $created;
    }
}
