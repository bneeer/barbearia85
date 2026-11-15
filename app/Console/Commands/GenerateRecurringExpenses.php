<?php

namespace App\Console\Commands;

use App\Services\ExpenseRecurrenceGenerator;
use Illuminate\Console\Command;

class GenerateRecurringExpenses extends Command
{
    protected $signature = 'finance:generate-recurring-expenses';

    protected $description = 'Generate this month’s expenses from active recurrence rules';

    public function handle(ExpenseRecurrenceGenerator $generator): int
    {
        $count = $generator->generateCurrentMonth();
        $this->info("Despesas recorrentes geradas: {$count}.");

        return self::SUCCESS;
    }
}
