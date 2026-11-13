<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentCheckout;
use App\Models\Barbershop;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Barbershop $shop;

    private User $admin;

    private User $barber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Barbershop::create([
            'name' => 'Centro', 'slug' => 'centro', 'timezone' => 'America/Sao_Paulo',
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin', 'barbershop_id' => $this->shop->id,
        ]);
        $this->barber = User::factory()->create([
            'role' => 'barber', 'barbershop_id' => $this->shop->id,
        ]);
    }

    private function openRegister(string $openingAmount = '100.00'): CashRegister
    {
        $this->actingAs($this->admin)->post('/cash-register', [
            'barbershop_id' => $this->shop->id,
            'opening_amount' => $openingAmount,
        ])->assertRedirect();

        return CashRegister::query()->whereNull('closed_at')->firstOrFail();
    }

    private function checkout(CashRegister $register, string $method, int $amount): void
    {
        $client = Client::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente',
            'email' => uniqid('cliente', true).'@example.com',
            'phone' => '11999999999',
        ]);
        $service = Service::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Corte',
            'description' => 'Corte',
            'duration' => 30,
            'price' => number_format($amount / 100, 2, '.', ''),
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'barbershop_id' => $this->shop->id,
            'client_id' => $client->id,
            'user_id' => $this->barber->id,
            'service_id' => $service->id,
            'start_time' => now()->subHour(),
            'end_time' => now()->subMinutes(30),
            'status' => 'completed',
        ]);
        AppointmentCheckout::create([
            'appointment_id' => $appointment->id,
            'cash_register_id' => $register->id,
            'created_by' => $this->admin->id,
            'services' => [['id' => $service->id, 'name' => $service->name]],
            'products' => [],
            'services_total_cents' => $amount,
            'products_total_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => $amount,
            'payment_method' => $method,
        ]);
    }

    public function test_only_administrators_can_open_one_register_per_unit(): void
    {
        $this->actingAs($this->barber)->get('/cash-register')->assertForbidden();
        $this->postJson('/cash-register', [
            'barbershop_id' => $this->shop->id,
            'opening_amount' => '10.00',
        ])->assertForbidden();

        $register = $this->openRegister();
        $this->postJson('/cash-register', [
            'barbershop_id' => $this->shop->id,
            'opening_amount' => '10.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('barbershop_id');

        $otherShop = Barbershop::create([
            'name' => 'Outra', 'slug' => 'outra', 'timezone' => 'America/Sao_Paulo',
        ]);
        $this->post('/cash-register', [
            'barbershop_id' => $otherShop->id,
            'opening_amount' => '0.00',
        ])->assertRedirect();
        $this->assertSame(2, CashRegister::whereNull('closed_at')->count());
        $this->assertNull($register->fresh()->closed_at);
    }

    public function test_amounts_accept_integer_values_and_comma_or_dot_decimal_separators(): void
    {
        $this->actingAs($this->admin)->postJson('/cash-register', [
            'barbershop_id' => $this->shop->id,
            'opening_amount' => 100,
        ])->assertRedirect();
        $register = CashRegister::query()->whereNull('closed_at')->firstOrFail();
        $this->assertSame(10000, $register->opening_amount_cents);

        $this->postJson("/cash-register/{$register->id}/withdrawals", [
            'barbershop_id' => $this->shop->id,
            'amount' => '10,25',
            'reason' => 'Retirada em formato local',
        ])->assertRedirect();
        $this->assertDatabaseHas('cash_register_withdrawals', [
            'cash_register_id' => $register->id,
            'amount_cents' => 1025,
        ]);
    }

    public function test_withdrawals_reduce_expected_cash_and_closure_records_differences(): void
    {
        $register = $this->openRegister();
        $this->checkout($register, 'cash', 5000);
        $this->checkout($register, 'pix', 3000);
        $this->checkout($register, 'credit_card', 2000);
        $this->checkout($register, 'debit_card', 500);

        $this->postJson("/cash-register/{$register->id}/withdrawals", [
            'barbershop_id' => $this->shop->id,
            'amount' => '10.00',
            'reason' => 'Retirada para depósito',
        ])->assertRedirect();
        $this->postJson("/cash-register/{$register->id}/withdrawals", [
            'barbershop_id' => $this->shop->id,
            'amount' => '200.00',
            'reason' => 'Valor acima do disponível',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->get("/cash-register?barbershop_id={$this->shop->id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('current.expected_cents.cash', 14000)
            ->where('current.expected_cents.pix', 3000)
            ->where('current.expected_cents.credit_card', 2000)
            ->where('current.expected_cents.debit_card', 500)
            ->where('current.withdrawals_total_cents', 1000));

        $data = [
            'barbershop_id' => $this->shop->id,
            'counted_cash' => '138.00',
            'counted_pix' => '30.00',
            'counted_credit_card' => '20.00',
            'counted_debit_card' => '5.00',
        ];
        $this->postJson("/cash-register/{$register->id}/close", $data)
            ->assertUnprocessable()->assertJsonValidationErrors('closing_note');
        $this->postJson("/cash-register/{$register->id}/close", [
            ...$data,
            'closing_note' => 'Diferença de dois reais identificada na contagem.',
        ])->assertRedirect();

        $this->assertDatabaseHas('cash_registers', [
            'id' => $register->id,
            'expected_cash_cents' => 14000,
            'counted_cash_cents' => 13800,
            'expected_pix_cents' => 3000,
            'counted_pix_cents' => 3000,
            'closing_note' => 'Diferença de dois reais identificada na contagem.',
        ]);
        $this->get("/cash-register?barbershop_id={$this->shop->id}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('current', null)
            ->where('history.0.closure.difference.cash', -200));
    }
}
