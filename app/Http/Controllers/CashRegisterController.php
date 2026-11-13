<?php

namespace App\Http\Controllers;

use App\Models\Barbershop;
use App\Models\CashRegister;
use App\Services\CashRegisterSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CashRegisterController extends Controller
{
    private const METHODS = ['cash', 'pix', 'credit_card', 'debit_card'];

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    private function cents(string|int|float $amount): int
    {
        $amount = str_replace(',', '.', (string) $amount);
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function amountRules(): array
    {
        return ['required', 'regex:/^\d{1,9}([,.]\d{1,2})?$/'];
    }

    public function index(Request $request, CashRegisterSummary $summary)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'barbershop_id' => ['nullable', 'integer', 'exists:barbershops,id'],
        ]);
        $barbershops = Barbershop::query()->orderBy('name')->get(['id', 'name', 'timezone']);
        $barbershopId = $data['barbershop_id']
            ?? $request->user()->barbershop_id
            ?? $barbershops->first()?->id;
        $shop = $barbershopId ? Barbershop::findOrFail($barbershopId) : null;
        $register = $shop
            ? CashRegister::query()->where('barbershop_id', $shop->id)
                ->whereNull('closed_at')->with(['openedBy', 'withdrawals.user'])->first()
            : null;

        return Inertia::render('CashRegister/Index', [
            'barbershops' => $barbershops->map(fn (Barbershop $barbershop) => [
                'id' => $barbershop->id,
                'name' => $barbershop->name,
                'timezone' => $barbershop->timezone,
            ]),
            'selectedBarbershopId' => $shop?->id,
            'current' => $register ? [
                'id' => $register->id,
                'opened_at' => $register->opened_at->toIso8601String(),
                'opened_by' => $register->openedBy->name,
                'opening_amount_cents' => $register->opening_amount_cents,
                'sales_cents' => $summary->sales($register),
                'expected_cents' => $summary->expected($register),
                'checkout_count' => $register->checkouts()->count(),
                'withdrawals_total_cents' => $register->withdrawals->sum('amount_cents'),
                'withdrawals' => $register->withdrawals->map(fn ($withdrawal) => [
                    'id' => $withdrawal->id,
                    'amount_cents' => $withdrawal->amount_cents,
                    'reason' => $withdrawal->reason,
                    'user' => $withdrawal->user->name,
                    'created_at' => $withdrawal->created_at->toIso8601String(),
                ]),
            ] : null,
            'history' => $shop ? CashRegister::query()
                ->where('barbershop_id', $shop->id)->whereNotNull('closed_at')
                ->with(['openedBy', 'closedBy', 'withdrawals.user'])->latest('closed_at')->limit(10)->get()
                ->map(fn (CashRegister $closed) => [
                    'id' => $closed->id,
                    'opened_at' => $closed->opened_at->toIso8601String(),
                    'closed_at' => $closed->closed_at->toIso8601String(),
                    'opened_by' => $closed->openedBy->name,
                    'closed_by' => $closed->closedBy->name,
                    'opening_amount_cents' => $closed->opening_amount_cents,
                    'closure' => $summary->closure($closed),
                    'closing_note' => $closed->closing_note,
                    'withdrawals' => $closed->withdrawals->map(fn ($withdrawal) => [
                        'amount_cents' => $withdrawal->amount_cents,
                        'reason' => $withdrawal->reason,
                        'user' => $withdrawal->user->name,
                        'created_at' => $withdrawal->created_at->toIso8601String(),
                    ]),
                ])->values() : [],
        ]);
    }

    public function open(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'opening_amount' => $this->amountRules(),
        ]);

        DB::transaction(function () use ($data, $request) {
            $shop = Barbershop::query()->lockForUpdate()->findOrFail($data['barbershop_id']);
            if ($shop->cashRegisters()->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages([
                    'barbershop_id' => 'Esta unidade já possui um caixa aberto.',
                ]);
            }

            $shop->cashRegisters()->create([
                'opened_by' => $request->user()->id,
                'opening_amount_cents' => $this->cents($data['opening_amount']),
                'opened_at' => now(),
            ]);
        }, 3);

        return back()->with('success', 'Caixa aberto.');
    }

    public function withdraw(Request $request, int $id, CashRegisterSummary $summary)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'amount' => $this->amountRules(),
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $id, $request, $summary) {
            $register = CashRegister::query()
                ->where('barbershop_id', $data['barbershop_id'])
                ->whereNull('closed_at')->lockForUpdate()->findOrFail($id);
            $amount = $this->cents($data['amount']);
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'A sangria deve ser maior que zero.']);
            }
            if ($amount > $summary->expected($register)['cash']) {
                throw ValidationException::withMessages([
                    'amount' => 'A sangria não pode superar o saldo esperado em dinheiro.',
                ]);
            }

            $register->withdrawals()->create([
                'user_id' => $request->user()->id,
                'amount_cents' => $amount,
                'reason' => trim($data['reason']),
            ]);
        }, 3);

        return back()->with('success', 'Sangria registrada.');
    }

    public function close(Request $request, int $id, CashRegisterSummary $summary)
    {
        $this->authorizeAdmin($request);
        $amountRules = $this->amountRules();
        $data = $request->validate([
            'barbershop_id' => ['required', 'integer', 'exists:barbershops,id'],
            'counted_cash' => $amountRules,
            'counted_pix' => $amountRules,
            'counted_credit_card' => $amountRules,
            'counted_debit_card' => $amountRules,
            'closing_note' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($data, $id, $request, $summary) {
            $register = CashRegister::query()
                ->where('barbershop_id', $data['barbershop_id'])
                ->whereNull('closed_at')->lockForUpdate()->findOrFail($id);
            $expected = $summary->expected($register);
            $counted = collect(self::METHODS)->mapWithKeys(fn ($method) => [
                $method => $this->cents($data["counted_{$method}"]),
            ])->all();
            $hasDifference = collect(self::METHODS)->contains(
                fn ($method) => $expected[$method] !== $counted[$method]
            );
            $note = trim($data['closing_note'] ?? '');
            if ($hasDifference && $note === '') {
                throw ValidationException::withMessages([
                    'closing_note' => 'Informe o motivo da divergência para fechar o caixa.',
                ]);
            }

            $register->update([
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'expected_cash_cents' => $expected['cash'],
                'expected_pix_cents' => $expected['pix'],
                'expected_credit_card_cents' => $expected['credit_card'],
                'expected_debit_card_cents' => $expected['debit_card'],
                'counted_cash_cents' => $counted['cash'],
                'counted_pix_cents' => $counted['pix'],
                'counted_credit_card_cents' => $counted['credit_card'],
                'counted_debit_card_cents' => $counted['debit_card'],
                'closing_note' => $note !== '' ? $note : null,
            ]);
        }, 3);

        return back()->with('success', 'Caixa fechado e conferência registrada.');
    }
}
