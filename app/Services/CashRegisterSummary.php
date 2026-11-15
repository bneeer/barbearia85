<?php

namespace App\Services;

use App\Models\AppointmentCheckout;
use App\Models\CashRegister;

class CashRegisterSummary
{
    private const METHODS = ['cash', 'pix', 'credit_card', 'debit_card'];

    public function expected(CashRegister $register): array
    {
        $sales = $this->sales($register);
        $withdrawals = (int) $register->withdrawals()->sum('amount_cents');
        $expensePayments = $this->expensePayments($register);

        return [
            'cash' => $register->opening_amount_cents + $sales['cash'] - $withdrawals - $expensePayments['cash'],
            'pix' => $sales['pix'] - $expensePayments['pix'],
            'credit_card' => $sales['credit_card'] - $expensePayments['credit_card'],
            'debit_card' => $sales['debit_card'] - $expensePayments['debit_card'],
        ];
    }

    public function expensePayments(CashRegister $register): array
    {
        $payments = $register->expensePayments()
            ->selectRaw('payment_method, SUM(amount_cents) as total_cents')
            ->groupBy('payment_method')
            ->pluck('total_cents', 'payment_method')
            ->map(fn ($amount) => (int) $amount)
            ->all();

        return collect(self::METHODS)->mapWithKeys(fn ($method) => [
            $method => $payments[$method] ?? 0,
        ])->all();
    }

    public function sales(CashRegister $register): array
    {
        $sales = AppointmentCheckout::query()
            ->where('cash_register_id', $register->id)
            ->selectRaw('payment_method, SUM(total_cents) as total_cents')
            ->groupBy('payment_method')
            ->pluck('total_cents', 'payment_method')
            ->map(fn ($amount) => (int) $amount)
            ->all();

        return collect(self::METHODS)->mapWithKeys(fn ($method) => [
            $method => $sales[$method] ?? 0,
        ])->all();
    }

    public function closure(CashRegister $register): array
    {
        $expected = collect(self::METHODS)->mapWithKeys(fn ($method) => [
            $method => $register->{"expected_{$method}_cents"},
        ])->all();
        $counted = collect(self::METHODS)->mapWithKeys(fn ($method) => [
            $method => $register->{"counted_{$method}_cents"},
        ])->all();

        return [
            'expected' => $expected,
            'counted' => $counted,
            'difference' => collect(self::METHODS)->mapWithKeys(fn ($method) => [
                $method => $counted[$method] - $expected[$method],
            ])->all(),
        ];
    }
}
