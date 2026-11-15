<?php

namespace App\Http\Controllers;

use App\Models\Barbershop;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseRecurrence;
use App\Services\CashRegisterSummary;
use App\Services\ExpenseRecurrenceGenerator;
use App\Services\FinancialSummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FinanceController extends Controller
{
    private const METHODS = ['cash', 'pix', 'credit_card', 'debit_card'];

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()->isAdmin() || $request->user()->isBarber(), 403);
    }

    private function amountRules(): array
    {
        return ['required', 'regex:/^\d{1,9}([,.]\d{1,2})?$/'];
    }

    private function cents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(
            explode('.', str_replace(',', '.', (string) $amount), 2),
            2,
            '0',
        );

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function index(Request $request, FinancialSummary $summary)
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'barbershop_id' => ['nullable', 'integer', 'exists:barbershops,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $user = $request->user();
        $shops = Barbershop::query()
            ->when(! $user->isAdmin(), fn ($query) => $query->whereHas(
                'users',
                fn ($users) => $users->whereKey($user->id),
            ))
            ->orderBy('name')->get(['id', 'name', 'timezone']);
        $shop = $shops->firstWhere('id', (int) ($data['barbershop_id'] ?? 0))
            ?? $shops->firstWhere('id', (int) $user->barbershop_id)
            ?? $shops->first();

        abort_if(isset($data['barbershop_id']) && ! $shop, 404);
        abort_if($shop && isset($data['barbershop_id']) && $shop->id !== (int) $data['barbershop_id'], 403);

        if (! $shop) {
            return Inertia::render($user->isAdmin() ? 'Finance/Index' : 'Finance/Commissions', [
                'barbershops' => [],
                'selectedBarbershopId' => null,
                'filters' => ['from' => '', 'to' => ''],
                'summary' => null,
                'expenses' => [],
                'recurrences' => [],
                'openCashRegisterId' => null,
            ]);
        }

        $today = CarbonImmutable::now($shop->timezone);
        $from = $data['from'] ?? $today->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today->toDateString();
        $start = CarbonImmutable::parse($from, $shop->timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $shop->timezone)->endOfDay();
        abort_if($start->diffInDays($end) > 365, 422, 'O período máximo é de 365 dias.');

        if (! $user->isAdmin()) {
            return Inertia::render('Finance/Commissions', [
                'barbershops' => $shops->map(fn ($item) => $item->only(['id', 'name'])),
                'selectedBarbershopId' => $shop->id,
                'filters' => ['from' => $from, 'to' => $to],
                'summary' => $summary->barberCommissions($shop, $user->id, $from, $to),
            ]);
        }

        $expenses = Expense::query()->where('barbershop_id', $shop->id)
            ->with(['payments.user'])
            ->orderByDesc('due_date')->orderByDesc('id')->limit(30)->get()
            ->map(function (Expense $expense) {
                $paid = (int) $expense->payments->sum('amount_cents');

                return [
                    'id' => $expense->id,
                    'title' => $expense->title,
                    'category' => $expense->category,
                    'amount_cents' => $expense->amount_cents,
                    'paid_cents' => $paid,
                    'remaining_cents' => max(0, $expense->amount_cents - $paid),
                    'competence_date' => $expense->competence_date->toDateString(),
                    'due_date' => $expense->due_date->toDateString(),
                    'recurring' => $expense->recurrence_id !== null,
                    'payments' => $expense->payments->map(fn (ExpensePayment $payment) => [
                        'amount_cents' => $payment->amount_cents,
                        'payment_method' => $payment->payment_method,
                        'paid_at' => $payment->paid_at->toIso8601String(),
                        'user' => $payment->user->name,
                    ]),
                ];
            });

        return Inertia::render('Finance/Index', [
            'barbershops' => $shops->map(fn ($item) => $item->only(['id', 'name', 'timezone'])),
            'selectedBarbershopId' => $shop->id,
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => $summary->adminSummary($shop, $from, $to),
            'expenses' => $expenses,
            'recurrences' => ExpenseRecurrence::query()->where('barbershop_id', $shop->id)
                ->orderBy('active', 'desc')->orderBy('title')->get()
                ->map(fn (ExpenseRecurrence $recurrence) => [
                    'id' => $recurrence->id,
                    'title' => $recurrence->title,
                    'category' => $recurrence->category,
                    'amount_cents' => $recurrence->amount_cents,
                    'due_day' => $recurrence->due_day,
                    'starts_on' => $recurrence->starts_on->toDateString(),
                    'ends_on' => $recurrence->ends_on?->toDateString(),
                    'active' => $recurrence->active,
                ]),
            'openCashRegisterId' => CashRegister::query()->where('barbershop_id', $shop->id)
                ->whereNull('closed_at')->value('id'),
        ]);
    }

    public function storeExpense(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => $this->amountRules(),
            'competence_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $amount = $this->cents($data['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'O valor da despesa deve ser maior que zero.']);
        }

        Expense::create([
            'barbershop_id' => $data['barbershop_id'],
            'created_by' => $request->user()->id,
            'title' => trim($data['title']),
            'category' => trim($data['category']),
            'amount_cents' => $amount,
            'competence_date' => $data['competence_date'],
            'due_date' => $data['due_date'],
        ]);

        return back()->with('success', 'Despesa cadastrada.');
    }

    public function storeRecurrence(Request $request, ExpenseRecurrenceGenerator $generator)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => $this->amountRules(),
            'due_day' => ['required', 'integer', 'between:1,31'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        $amount = $this->cents($data['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'O valor da recorrência deve ser maior que zero.']);
        }

        ExpenseRecurrence::create([
            'barbershop_id' => $data['barbershop_id'],
            'created_by' => $request->user()->id,
            'title' => trim($data['title']),
            'category' => trim($data['category']),
            'amount_cents' => $amount,
            'due_day' => $data['due_day'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
        ]);
        $generator->generateCurrentMonth();

        return back()->with('success', 'Despesa recorrente cadastrada.');
    }

    public function toggleRecurrence(Request $request, int $id)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $recurrence = ExpenseRecurrence::findOrFail($id);
        $recurrence->update(['active' => $data['active']]);

        return back()->with('success', 'Recorrência atualizada.');
    }

    public function payExpense(
        Request $request,
        int $id,
        CashRegisterSummary $registerSummary,
    ) {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'amount' => $this->amountRules(),
            'payment_method' => ['required', Rule::in(self::METHODS)],
            'use_open_cash' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $amount = $this->cents($data['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'O pagamento deve ser maior que zero.']);
        }

        DB::transaction(function () use ($request, $id, $data, $amount, $registerSummary) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($id);
            $paid = (int) $expense->payments()->sum('amount_cents');
            $remaining = $expense->amount_cents - $paid;
            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento não pode superar o saldo pendente da despesa.',
                ]);
            }

            $register = null;
            if ($data['use_open_cash']) {
                $register = CashRegister::query()->where('barbershop_id', $expense->barbershop_id)
                    ->whereNull('closed_at')->lockForUpdate()->first();
                if (! $register) {
                    throw ValidationException::withMessages([
                        'use_open_cash' => 'Abra o caixa da unidade antes de registrar esta saída pelo caixa.',
                    ]);
                }
                if ($amount > $registerSummary->expected($register)[$data['payment_method']]) {
                    throw ValidationException::withMessages([
                        'amount' => 'O pagamento supera o saldo esperado no caixa para essa forma de pagamento.',
                    ]);
                }
            }

            $expense->payments()->create([
                'user_id' => $request->user()->id,
                'cash_register_id' => $register?->id,
                'amount_cents' => $amount,
                'payment_method' => $data['payment_method'],
                'paid_at' => CarbonImmutable::now($expense->barbershop->timezone)->utc(),
                'notes' => trim($data['notes'] ?? '') ?: null,
            ]);
        }, 3);

        return back()->with('success', 'Pagamento registrado.');
    }
}
