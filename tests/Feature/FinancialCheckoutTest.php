<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentCheckout;
use App\Models\Barbershop;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Service;
use App\Models\Supply;
use App\Models\User;
use App\Services\FinancialSummary;
use App\Services\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Barbershop $shop;

    private User $admin;

    private User $barber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Sao_Paulo')->utc());
        $this->shop = Barbershop::create([
            'name' => 'Centro', 'slug' => 'centro', 'timezone' => 'America/Sao_Paulo',
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin', 'barbershop_id' => $this->shop->id,
        ]);
        $this->barber = User::factory()->create([
            'role' => 'barber', 'barbershop_id' => $this->shop->id,
        ]);
        CashRegister::create([
            'barbershop_id' => $this->shop->id,
            'opened_by' => $this->admin->id,
            'opening_amount_cents' => 0,
            'opened_at' => now(),
        ]);
    }

    private function appointment(Service $service): Appointment
    {
        $client = Client::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'phone' => '11999999999',
        ]);

        return Appointment::create([
            'barbershop_id' => $this->shop->id,
            'user_id' => $this->barber->id,
            'client_id' => $client->id,
            'service_id' => $service->id,
            'start_time' => CarbonImmutable::parse('2026-11-16 09:00', 'America/Sao_Paulo')->utc(),
            'end_time' => CarbonImmutable::parse('2026-11-16 09:40', 'America/Sao_Paulo')->utc(),
            'status' => 'scheduled',
        ]);
    }

    private function version(Appointment $appointment): string
    {
        return hash('sha256', implode('|', [
            $appointment->start_time->toIso8601String(),
            $appointment->end_time->toIso8601String(),
            $appointment->status,
            $appointment->updated_at->toIso8601String(),
        ]));
    }

    private function payload(Appointment $appointment, Service $service, Supply $supply): array
    {
        return [
            'barbershop_id' => $this->shop->id,
            'version' => $this->version($appointment),
            'services_total_cents' => 5000,
            'services' => [['id' => $service->id, 'price_cents' => 5000]],
            'products' => [],
            'discount_cents' => 500,
            'payment_method' => 'pix',
            'confirmed_consumptions' => [[
                'service_id' => $service->id,
                'supply_id' => $supply->id,
            ]],
        ];
    }

    public function test_checkout_snapshots_commission_net_value_and_confirmed_supply_cost(): void
    {
        $service = Service::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Corte',
            'description' => 'Corte',
            'duration' => 40,
            'price' => '50.00',
            'commission_percent' => '10.00',
        ]);
        $supply = Supply::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Loção',
            'unit' => 'ml',
        ]);
        app(StockLedger::class)->record($supply, 10000, 'entry', 'Compra', $this->admin, null, 2000);
        $service->supplies()->attach($supply->id, ['quantity' => '2.000']);
        $appointment = $this->appointment($service);

        $response = $this->actingAs($this->admin)->postJson(
            "/schedule/appointments/{$appointment->id}/checkout",
            $this->payload($appointment, $service, $supply),
        )->assertOk()->assertJsonPath('warnings', []);

        $checkout = AppointmentCheckout::firstOrFail();
        $this->assertSame(450, $checkout->commission_cents);
        $this->assertSame(400, $checkout->supply_cost_cents);
        $this->assertSame(500, $checkout->services[0]['discount_cents']);
        $this->assertSame(4500, $checkout->services[0]['net_cents']);
        $this->assertSame(450, $checkout->services[0]['commission_cents']);
        $this->assertSame('debited', $checkout->services[0]['supplies'][0]['inventory_status']);
        $this->assertSame('8.000', $supply->fresh()->stock_quantity);
        $this->assertSame('Atendimento finalizado.', $response->json('message'));
        $summary = app(FinancialSummary::class)->adminSummary(
            $this->shop,
            '2026-11-16',
            '2026-11-16',
        );
        $this->assertSame(4500, $summary['revenue_cents']);
        $this->assertSame(400, $summary['supply_cost_cents']);
        $this->assertSame(450, $summary['commission_cents']);
        $this->assertSame(3650, $summary['estimated_profit_cents']);
    }

    public function test_insufficient_confirmed_supply_does_not_block_checkout_or_create_negative_stock(): void
    {
        $service = Service::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Corte',
            'description' => 'Corte',
            'duration' => 40,
            'price' => '50.00',
        ]);
        $supply = Supply::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Loção',
            'unit' => 'ml',
        ]);
        app(StockLedger::class)->record($supply, 1000, 'entry', 'Compra', $this->admin, null, 500);
        $service->supplies()->attach($supply->id, ['quantity' => '2.000']);
        $appointment = $this->appointment($service);

        $this->actingAs($this->admin)->postJson(
            "/schedule/appointments/{$appointment->id}/checkout",
            $this->payload($appointment, $service, $supply),
        )->assertOk()->assertJsonPath('warnings.0', 'Estoque insuficiente para Loção; a baixa foi ignorada.');

        $this->assertSame('completed', $appointment->fresh()->status);
        $this->assertSame('1.000', $supply->fresh()->stock_quantity);
        $this->assertSame('insufficient_stock', AppointmentCheckout::firstOrFail()
            ->services[0]['supplies'][0]['inventory_status']);
    }
}
