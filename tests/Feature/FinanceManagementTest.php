<?php

namespace Tests\Feature;

use App\Models\BarberSalaryRate;
use App\Models\Barbershop;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\Supply;
use App\Models\User;
use App\Services\CashRegisterSummary;
use App\Services\CheckoutFinancials;
use App\Services\FinancialSummary;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceManagementTest extends TestCase
{
    use RefreshDatabase;

    private Barbershop $shop;

    private User $admin;

    private User $barber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('America/Sao_Paulo')->setDate(2026, 11, 16)->setTime(10, 0)->utc());
        $this->shop = Barbershop::create([
            'name' => 'Centro', 'slug' => 'centro', 'timezone' => 'America/Sao_Paulo',
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin', 'barbershop_id' => $this->shop->id,
        ]);
        $this->barber = User::factory()->create([
            'role' => 'barber', 'barbershop_id' => $this->shop->id,
        ]);
        $this->barber->barbershops()->attach($this->shop);
    }

    public function test_expenses_support_partial_settlement_and_only_cash_session_payments_affect_register(): void
    {
        $this->actingAs($this->admin)->post('/finance/expenses', [
            'barbershop_id' => $this->shop->id,
            'title' => 'Conta de energia',
            'category' => 'Utilidades',
            'amount' => '100.00',
            'competence_date' => '2026-11-01',
            'due_date' => '2026-11-20',
        ])->assertRedirect();
        $expense = Expense::firstOrFail();
        $register = CashRegister::create([
            'barbershop_id' => $this->shop->id,
            'opened_by' => $this->admin->id,
            'opening_amount_cents' => 10000,
            'opened_at' => now(),
        ]);

        $this->post("/finance/expenses/{$expense->id}/payments", [
            'amount' => '30.00',
            'payment_method' => 'cash',
            'use_open_cash' => true,
        ])->assertRedirect();
        $this->assertSame(7000, app(CashRegisterSummary::class)->expected($register)['cash']);

        $this->post("/finance/expenses/{$expense->id}/payments", [
            'amount' => '70.00',
            'payment_method' => 'pix',
            'use_open_cash' => false,
        ])->assertRedirect();
        $this->assertDatabaseCount('expense_payments', 2);
        $this->assertSame(7000, app(CashRegisterSummary::class)->expected($register)['cash']);
        $this->postJson("/finance/expenses/{$expense->id}/payments", [
            'amount' => '0.01',
            'payment_method' => 'cash',
            'use_open_cash' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_recurring_expenses_generate_idempotently_and_barbers_only_see_their_commissions(): void
    {
        $this->actingAs($this->admin)->post('/finance/recurrences', [
            'barbershop_id' => $this->shop->id,
            'title' => 'Aluguel',
            'category' => 'Imóvel',
            'amount' => '2500.00',
            'due_day' => 31,
            'starts_on' => '2026-11-01',
        ])->assertRedirect();

        $this->assertDatabaseCount('expenses', 1);
        $this->actingAs($this->admin)->post('/finance/recurrences', [
            'barbershop_id' => $this->shop->id,
            'title' => 'Serviço encerrado',
            'category' => 'Teste',
            'amount' => '25.00',
            'due_day' => 10,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-09',
        ])->assertRedirect();
        $this->assertDatabaseCount('expenses', 1);
        Artisan::call('finance:generate-recurring-expenses');
        Artisan::call('finance:generate-recurring-expenses');
        $this->assertDatabaseCount('expenses', 1);
        $this->actingAs($this->barber)->get('/finance')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/Commissions')
                ->where('summary.commission_cents', 0)
                ->missing('expenses'));
        $this->post('/finance/expenses', [
            'barbershop_id' => $this->shop->id,
            'title' => 'Não autorizado',
            'category' => 'Teste',
            'amount' => '10.00',
            'competence_date' => '2026-11-01',
            'due_date' => '2026-11-20',
        ])->assertForbidden();
    }

    public function test_discount_allocation_preserves_every_cent_and_commission_uses_net_service_value(): void
    {
        $calculator = app(CheckoutFinancials::class);
        $lines = $calculator->allocateDiscount([
            ['key' => 'service:1', 'gross_cents' => 10001],
            ['key' => 'service:2', 'gross_cents' => 9999],
            ['key' => 'product:3', 'gross_cents' => 10000],
        ], 10001);

        $this->assertSame(10001, array_sum(array_column($lines, 'discount_cents')));
        $this->assertSame(19999, array_sum(array_column($lines, 'net_cents')));
        $firstService = collect($lines)->firstWhere('key', 'service:1');
        $this->assertSame(
            (int) round($firstService['net_cents'] * 0.1),
            $calculator->commissionCents($firstService['net_cents'], '10.00'),
        );
    }

    public function test_inventory_entries_update_weighted_average_cost_without_floating_point_drift(): void
    {
        $supply = Supply::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Loção', 'unit' => 'ml',
        ]);
        $ledger = app(StockLedger::class);
        $ledger->record($supply, 10000, 'entry', 'Compra A', $this->admin, null, 2000);
        $supply->refresh();
        $ledger->record($supply, 10000, 'entry', 'Compra B', $this->admin, null, 3000);
        $supply->refresh();

        $this->assertSame(250000, $supply->average_cost_milli_cents);
        $this->assertSame(1250, $ledger->costForQuantity(5000, $supply->average_cost_milli_cents));
    }

    public function test_new_purchase_does_not_assign_a_zero_cost_to_an_unknown_opening_balance(): void
    {
        $supply = Supply::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Shampoo',
            'unit' => 'ml',
            'stock_quantity' => '10.000',
        ]);
        $ledger = app(StockLedger::class);
        $ledger->record($supply, 10000, 'entry', 'Compra nova', $this->admin, null, 3000);
        $supply->refresh();

        $this->assertNull($supply->average_cost_milli_cents);

        $ledger->record($supply, -20000, 'exit', 'Consumo do saldo antigo', $this->admin);
        $ledger->record($supply->refresh(), 1000, 'entry', 'Novo saldo conhecido', $this->admin, null, 500);

        $this->assertSame(500000, $supply->fresh()->average_cost_milli_cents);
    }

    public function test_salary_base_uses_historical_rates_and_prorates_each_rate_by_calendar_days(): void
    {
        BarberSalaryRate::create([
            'barbershop_id' => $this->shop->id,
            'user_id' => $this->barber->id,
            'salary_base_cents' => 310000,
            'effective_from' => '2026-11-01',
            'effective_until' => '2026-11-15',
        ]);
        BarberSalaryRate::create([
            'barbershop_id' => $this->shop->id,
            'user_id' => $this->barber->id,
            'salary_base_cents' => 620000,
            'effective_from' => '2026-11-16',
        ]);

        $summary = app(FinancialSummary::class)->adminSummary(
            $this->shop,
            '2026-11-01',
            '2026-11-30',
        );

        $this->assertSame(465000, $summary['salary_base_cents']);
    }

    public function test_barber_salary_changes_preserve_previous_effective_dates(): void
    {
        $this->actingAs($this->admin)->put("/barbers/{$this->barber->id}", [
            'name' => $this->barber->name,
            'email' => $this->barber->email,
            'phone' => '',
            'service_ids' => [],
            'barbershop_ids' => [$this->shop->id],
            'salary_base' => [(string) $this->shop->id => '3100.00'],
        ])->assertSessionHasNoErrors();

        $this->travelTo(now()->addDay());
        $this->put("/barbers/{$this->barber->id}", [
            'name' => $this->barber->name,
            'email' => $this->barber->email,
            'phone' => '',
            'service_ids' => [],
            'barbershop_ids' => [$this->shop->id],
            'salary_base' => [(string) $this->shop->id => '6200.00'],
        ])->assertSessionHasNoErrors();

        $rates = BarberSalaryRate::query()->where('user_id', $this->barber->id)
            ->orderBy('effective_from')->get();
        $this->assertCount(2, $rates);
        $this->assertSame('2026-11-16', $rates[0]->effective_from->toDateString());
        $this->assertSame('2026-11-16', $rates[0]->effective_until->toDateString());
        $this->assertSame('2026-11-17', $rates[1]->effective_from->toDateString());
        $this->assertSame(620000, $this->barber->barbershops()->first()->pivot->salary_base_cents);
    }
}
