<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardStockTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $name): Barbershop
    {
        return Barbershop::create([
            'name' => $name,
            'slug' => strtolower($name),
            'timezone' => 'America/Sao_Paulo',
        ]);
    }

    private function product(Barbershop $shop, string $name, int $stock, int $minimum): Product
    {
        return Product::create([
            'barbershop_id' => $shop->id,
            'name' => $name,
            'brand' => 'Marca',
            'description' => $name,
            'price' => '10.00',
            'stock_quantity' => $stock,
            'min_stock' => $minimum,
        ]);
    }

    private function supply(Barbershop $shop, string $name, string $stock, string $minimum): Supply
    {
        return Supply::create([
            'barbershop_id' => $shop->id,
            'name' => $name,
            'unit' => 'ml',
            'stock_quantity' => $stock,
            'min_stock' => $minimum,
        ]);
    }

    private function checkout(User $admin, Barbershop $shop): int
    {
        $client = Client::create([
            'barbershop_id' => $shop->id,
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'phone' => '11999998888',
        ]);
        $service = Service::create([
            'barbershop_id' => $shop->id,
            'name' => 'Corte',
            'description' => 'Corte',
            'duration' => 30,
            'price' => '50.00',
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id,
            'user_id' => $admin->id,
            'client_id' => $client->id,
            'service_id' => $service->id,
            'start_time' => '2026-11-09 12:00:00',
            'end_time' => '2026-11-09 12:30:00',
            'status' => 'completed',
        ]);

        return $appointment->checkout()->create([
            'created_by' => $admin->id,
            'services' => [],
            'products' => [],
            'services_total_cents' => 0,
            'products_total_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 0,
            'payment_method' => 'pix',
        ])->id;
    }

    private function movement(
        Product|Supply $item,
        User $admin,
        string $type,
        string $quantity,
        string $date,
        ?int $checkoutId = null,
    ): void {
        $movement = $item->stockMovements()->create([
            'barbershop_id' => $item->barbershop_id,
            'user_id' => $admin->id,
            'appointment_checkout_id' => $checkoutId,
            'stockable_name' => $item->name,
            'unit' => $item instanceof Supply ? $item->unit : 'un',
            'type' => $type,
            'quantity' => $quantity,
            'balance_before' => '10.000',
            'balance_after' => '10.000',
            'reason' => 'Teste',
        ]);
        $movement->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();
    }

    public function test_admin_dashboard_scopes_current_stock_and_period_movements_by_selected_shop(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'America/Sao_Paulo')->utc());
        $shop = $this->shop('Centro');
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $lowProduct = $this->product($shop, 'Pomada', 2, 5);
        $outProduct = $this->product($shop, 'Óleo', 0, 0);
        $topProduct = $this->product($shop, 'Cera', 8, 2);
        $this->supply($shop, 'Loção baixa', '1.500', '2.000');
        $this->supply($shop, 'Loção disponível', '10.000', '2.000');
        $this->product($other, 'Produto outra unidade', 0, 3);

        $checkoutOne = $this->checkout($admin, $shop);
        $checkoutTwo = $this->checkout($admin, $shop);
        $checkoutOld = $this->checkout($admin, $shop);
        $this->movement($lowProduct, $admin, 'exit', '-2.000', '2026-11-09 15:00:00', $checkoutOne);
        $this->movement($lowProduct, $admin, 'exit', '-1.000', '2026-11-09 16:00:00', $checkoutTwo);
        $this->movement($topProduct, $admin, 'exit', '-4.000', '2026-11-09 16:30:00', $checkoutTwo);
        $this->movement($topProduct, $admin, 'exit', '-7.000', '2026-11-08 16:30:00', $checkoutOld);
        $this->movement($lowProduct, $admin, 'entry', '5.000', '2026-11-09 17:00:00');
        $this->movement($outProduct, $admin, 'exit', '-1.000', '2026-11-09 17:30:00');
        $this->movement($outProduct, $admin, 'adjustment', '1.000', '2026-11-09 18:00:00');
        $this->movement(
            Product::query()->where('barbershop_id', $other->id)->firstOrFail(),
            $admin,
            'exit',
            '-9.000',
            '2026-11-09 18:00:00',
            $this->checkout($admin, $other),
        );

        $this->actingAs($admin)
            ->get('/dashboard?range_from=2026-11-09&range_to=2026-11-09&barbershop_id='.$shop->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stockSummary.current.products_to_restock', 1)
            ->where('stockSummary.current.supplies_to_restock', 1)
            ->where('stockSummary.current.products_out_of_stock', 1)
            ->where('stockSummary.current.supplies_out_of_stock', 0)
            ->where('stockSummary.period.units_sold', 7)
            ->where('stockSummary.period.sales_count', 2)
            ->where('stockSummary.period.entries_count', 1)
            ->where('stockSummary.period.manual_exits_count', 1)
            ->where('stockSummary.period.adjustments_count', 1)
            ->where('stockSummary.period.top_sellers.0.id', $topProduct->id)
            ->where('stockSummary.period.top_sellers.0.quantity', 4)
            ->where('stockSummary.period.top_sellers.1.id', $lowProduct->id));
    }

    public function test_stock_snapshot_is_not_filtered_by_selected_dates_and_is_admin_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'America/Sao_Paulo')->utc());
        $shop = $this->shop('Centro');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $product = $this->product($shop, 'Pomada', 0, 4);
        $checkoutId = $this->checkout($admin, $shop);
        $this->movement($product, $admin, 'exit', '-1.000', '2026-11-08 16:00:00', $checkoutId);

        $this->actingAs($admin)
            ->get('/dashboard?range_from=2026-11-09&range_to=2026-11-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stockSummary.current.products_to_restock', 1)
                ->where('stockSummary.current.products_out_of_stock', 1)
                ->where('stockSummary.period.units_sold', 0)
                ->where('stockSummary.period.sales_count', 0));

        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $this->actingAs($barber)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->missing('stockSummary'));
    }
}
