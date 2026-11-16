<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\Product;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $slug): Barbershop
    {
        return Barbershop::create(['name' => $slug, 'slug' => $slug]);
    }

    public function test_inventory_lists_only_the_current_shops_products_and_supplies(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        Product::create([
            'barbershop_id' => $shop->id, 'name' => 'Pomada', 'brand' => 'Marca',
            'description' => 'Produto', 'price' => '35.00', 'stock_quantity' => 0, 'min_stock' => 2,
        ]);
        Supply::create([
            'barbershop_id' => $shop->id, 'name' => 'Loção', 'unit' => 'ml',
            'stock_quantity' => '125.500', 'min_stock' => '200.000',
        ]);
        Product::create([
            'barbershop_id' => $other->id, 'name' => 'Produto de outra unidade',
            'brand' => 'Marca', 'description' => 'Produto', 'price' => '20.00',
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);

        $this->actingAs($admin)->get('/inventory')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Inventory/Index')
                ->where('counts.products', 1)
                ->where('counts.supplies', 1)
                ->where('counts.low_stock', 2)
                ->has('items.data', 2)
                ->where('items.data.0.name', 'Loção')
                ->where('items.data.1.name', 'Pomada')
                ->where('filters.status', 'all'));
        $this->get('/inventory?type=product&status=out')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)
                ->where('items.data.0.type', 'product'));
    }

    public function test_admin_can_create_supplies_and_record_audited_fractional_movements(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin)->from('/inventory')->post('/inventory/supplies', [
            'name' => 'Loção pós-barba', 'brand' => 'Marca', 'description' => 'Uso interno',
            'unit' => 'ml', 'min_stock' => '100.500',
        ])->assertRedirect('/inventory');
        $supply = Supply::firstOrFail();
        $this->assertSame('0.000', $supply->stock_quantity);
        $this->assertDatabaseHas('supplies', [
            'id' => $supply->id, 'barbershop_id' => $shop->id, 'unit' => 'ml',
            'min_stock' => '100.500',
        ]);

        $this->from('/inventory')->post('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'entry',
            'quantity' => '500.125', 'total_cost' => '50.00', 'reason' => 'Compra inicial',
        ])->assertRedirect('/inventory');
        $supply->refresh();
        $this->assertSame('500.125', $supply->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'stockable_type' => Supply::class, 'stockable_id' => $supply->id,
            'user_id' => $admin->id, 'type' => 'entry', 'quantity' => '500.125',
            'balance_before' => '0.000', 'balance_after' => '500.125',
            'reason' => 'Compra inicial',
        ]);

        $this->from('/inventory')->post('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'adjustment',
            'quantity' => '3.500', 'reason' => 'Contagem física',
        ])->assertRedirect('/inventory');
        $supply->refresh();
        $this->assertSame('3.500', $supply->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'stockable_id' => $supply->id, 'type' => 'adjustment',
            'quantity' => '-496.625', 'balance_after' => '3.500',
        ]);

        $this->postJson('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'exit',
            'quantity' => '4', 'reason' => 'Consumo',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame('3.500', $supply->fresh()->stock_quantity);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_product_stock_only_accepts_whole_units_and_supply_unit_is_immutable_after_movements(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $product = Product::create([
            'barbershop_id' => $shop->id, 'name' => 'Pomada', 'brand' => 'Marca',
            'description' => 'Produto', 'price' => '35.00',
        ]);
        $supply = Supply::create([
            'barbershop_id' => $shop->id, 'name' => 'Creme', 'unit' => 'g',
        ]);
        $this->actingAs($admin);

        $this->postJson('/inventory/movements', [
            'item_type' => 'product', 'item_id' => $product->id, 'type' => 'entry',
            'quantity' => '1.500', 'total_cost' => '35.00', 'reason' => 'Compra',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->post('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'entry',
            'quantity' => '5', 'total_cost' => '20.00', 'reason' => 'Compra',
        ])->assertRedirect();
        $this->putJson("/inventory/supplies/{$supply->id}", [
            'name' => 'Creme', 'unit' => 'kg', 'min_stock' => '0',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit');

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame('g', $supply->fresh()->unit);
    }

    public function test_only_admins_with_a_shop_can_manage_inventory(): void
    {
        $shop = $this->shop('principal');
        $supply = Supply::create([
            'barbershop_id' => $shop->id, 'name' => 'Creme', 'unit' => 'g',
        ]);

        $this->get('/inventory')->assertRedirect('/login');
        foreach ([
            ['role' => 'barber', 'barbershop_id' => $shop->id],
            ['role' => 'admin', 'barbershop_id' => null],
        ] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            $this->get('/inventory')->assertForbidden();
            $this->post('/inventory/supplies', [
                'name' => 'Loção', 'unit' => 'ml', 'min_stock' => '0',
            ])->assertForbidden();
            $this->post('/inventory/movements', [
                'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'entry',
                'quantity' => '1', 'reason' => 'Compra',
            ])->assertForbidden();
            $this->delete("/inventory/supplies/{$supply->id}")->assertForbidden();
        }
    }

    public function test_initial_supply_entry_supports_fractions_and_weighted_average(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin)->from('/inventory')->post('/inventory/supplies', [
            'name' => 'Creme', 'unit' => 'kg', 'min_stock' => '0.500',
            'initial_entry' => true, 'initial_quantity' => '0.500', 'initial_total_cost' => '10,00',
        ])->assertRedirect('/inventory')->assertSessionHasNoErrors();
        $supply = Supply::firstOrFail();
        $this->assertSame('0.500', $supply->stock_quantity);
        $this->assertSame(2000000, $supply->average_cost_milli_cents);
        $this->post('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'entry',
            'quantity' => '1.500', 'total_cost' => '60.00', 'reason' => 'Compra',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2.000', $supply->fresh()->stock_quantity);
        $this->assertSame(3500000, $supply->fresh()->average_cost_milli_cents);
        $this->put("/inventory/supplies/{$supply->id}", [
            'name' => 'Creme novo', 'unit' => 'kg', 'min_stock' => '1',
            'stock_quantity' => 100, 'average_cost_milli_cents' => 0,
            'initial_entry' => true, 'initial_quantity' => '100', 'initial_total_cost' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2.000', $supply->fresh()->stock_quantity);
        $this->assertSame(3500000, $supply->fresh()->average_cost_milli_cents);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_invalid_initial_supply_entry_rolls_back_and_absent_entry_keeps_cost_unknown(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);
        foreach ([
            ['initial_quantity' => '1.500', 'initial_total_cost' => '1'],
            ['initial_quantity' => '1'],
            ['initial_quantity' => '1', 'initial_total_cost' => '1.234'],
        ] as $entry) {
            $this->postJson('/inventory/supplies', [
                'name' => 'Descartável', 'unit' => 'un', 'min_stock' => '0',
                'initial_entry' => true, ...$entry,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('supplies', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->post('/inventory/supplies', [
            'name' => 'Descartável', 'unit' => 'un', 'min_stock' => '0',
            'initial_entry' => false, 'initial_quantity' => 'invalid', 'initial_total_cost' => '-1',
        ])->assertSessionHasNoErrors();
        $supply = Supply::firstOrFail();
        $this->assertSame('0.000', $supply->stock_quantity);
        $this->assertNull($supply->average_cost_milli_cents);
    }

    public function test_archiving_supply_checks_stock_active_services_and_shop_and_preserves_history(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin)->post('/inventory/supplies', [
            'name' => 'Creme', 'unit' => 'g', 'min_stock' => '0',
            'initial_entry' => true, 'initial_quantity' => '1', 'initial_total_cost' => '1',
        ]);
        $supply = Supply::firstOrFail();
        $url = "/inventory/supplies/{$supply->id}";
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('archive');
        $this->post('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'exit',
            'quantity' => '1', 'reason' => 'Consumo',
        ])->assertSessionHasNoErrors();
        $service = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Corte', 'description' => 'Corte',
            'duration' => 30, 'price' => '50.00', 'is_active' => true,
        ]);
        $service->supplies()->attach($supply->id, ['quantity' => '1']);
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('archive');
        $this->assertDatabaseHas('supplies', ['id' => $supply->id, 'deleted_at' => null]);
        $foreign = Supply::create(['barbershop_id' => $other->id, 'name' => 'Outro', 'unit' => 'un']);
        $this->deleteJson("/inventory/supplies/{$foreign->id}")->assertNotFound();
        $this->get("/inventory?action=show&item_type=supply&item_id={$foreign->id}")->assertNotFound();
        $service->update(['is_active' => false]);
        $this->from('/inventory')->delete($url)->assertRedirect('/inventory');
        $this->assertSoftDeleted('supplies', ['id' => $supply->id]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('service_supplies', 1);
        $this->get('/inventory')->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->postJson('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'entry',
            'quantity' => '1', 'total_cost' => '1', 'reason' => 'Compra',
        ])->assertNotFound();
    }

    public function test_entry_does_not_invent_cost_for_legacy_stock(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $supply = Supply::create([
            'barbershop_id' => $shop->id, 'name' => 'Loção', 'unit' => 'ml', 'stock_quantity' => '100',
        ]);
        $this->actingAs($admin)->post('/inventory/movements', [
            'item_type' => 'supply', 'item_id' => $supply->id, 'type' => 'entry',
            'quantity' => '100', 'total_cost' => '10', 'reason' => 'Compra',
        ])->assertSessionHasNoErrors();
        $this->assertSame('200.000', $supply->fresh()->stock_quantity);
        $this->assertNull($supply->fresh()->average_cost_milli_cents);
    }
}
