<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $slug): Barbershop
    {
        return Barbershop::create(['name' => $slug, 'slug' => $slug]);
    }

    private function data(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Pomada',
            'brand' => 'Marca A',
            'description' => 'Pomada modeladora para cabelo',
            'price' => '35.50',
        ], $attributes);
    }

    private function product(int $shopId, array $attributes = []): int
    {
        return DB::table('products')->insertGetId([
            ...$this->data($attributes),
            'barbershop_id' => $shopId,
            'created_at' => now(new \DateTimeZone('America/Sao_Paulo')),
            'updated_at' => now(new \DateTimeZone('America/Sao_Paulo')),
        ]);
    }

    public function test_admin_can_create_view_edit_update_and_delete_a_product(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get('/products/create')->assertRedirect(route('inventory.index', ['action' => 'create', 'item_type' => 'product']));
        $this->from('/inventory')->post('/products', $this->data(['barbershop_id' => $other->id]))->assertRedirect('/inventory');
        $this->assertDatabaseHas('products', ['barbershop_id' => $shop->id, ...$this->data()]);
        $id = DB::table('products')->value('id');

        $this->get("/products/$id")->assertRedirect(route('inventory.index', ['action' => 'show', 'item_type' => 'product', 'item_id' => $id]));
        $this->get("/products/$id/edit")->assertRedirect(route('inventory.index', ['action' => 'edit', 'item_type' => 'product', 'item_id' => $id]));
        $this->get("/inventory?action=edit&item_type=product&item_id=$id")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Inventory/Index')
                ->where('dialog.item.id', $id)->where('dialog.item.name', 'Pomada')
                ->where('dialog.item.brand', 'Marca A')
                ->where('dialog.item.description', 'Pomada modeladora para cabelo'));
        $updated = $this->data([
            'name' => 'Óleo para barba',
            'brand' => 'Marca B',
            'description' => 'Óleo hidratante',
            'price' => '42.90',
        ]);
        $this->from('/inventory')->put("/products/$id", [...$updated, 'barbershop_id' => $other->id])->assertRedirect('/inventory');
        $this->assertDatabaseHas('products', ['id' => $id, 'barbershop_id' => $shop->id, ...$updated]);

        $this->from('/inventory')->delete("/products/$id")->assertRedirect('/inventory');
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->get("/products/$id")->assertNotFound();
        $this->get("/products/$id/edit")->assertNotFound();
        $this->put("/products/$id", $this->data())->assertNotFound();
        $this->delete("/products/$id")->assertNotFound();
        $this->get('/inventory?type=product')->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
    }

    public function test_list_searches_name_and_brand_and_paginates_only_products_from_the_admins_shop(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        for ($i = 0; $i < 16; $i++) {
            $this->product($shop->id);
        }
        $this->product($other->id);
        $this->product($shop->id, ['name' => 'Óleo', 'brand' => 'Marca B']);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get('/products?search=Pomada&page=2')
            ->assertRedirect(route('inventory.index', ['type' => 'product', 'search' => 'Pomada', 'page' => 2]));
        $this->get('/inventory?type=product&search=Pomada')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Inventory/Index')
                ->where('items.total', 16)->has('items.data', 15)->where('filters.search', 'Pomada'));
        $this->get('/inventory?type=product&search=Pomada&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
        $this->get('/inventory?type=product&search=Marca%20A')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 16));
        $this->get('/inventory?type=product&search=inexistente')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 0));
        $this->get('/inventory?type=product')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('items.total', 17)->where('filters.search', ''));
    }

    public function test_admin_cannot_access_or_modify_another_shops_product(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $id = $this->product($other->id);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get("/products/$id")->assertNotFound();
        $this->get("/products/$id/edit")->assertNotFound();
        $this->put("/products/$id", $this->data())->assertNotFound();
        $this->delete("/products/$id")->assertNotFound();
        $this->get("/inventory?action=edit&item_type=product&item_id=$id")->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Pomada', 'deleted_at' => null]);
    }

    public function test_only_admins_with_a_shop_can_use_the_crud(): void
    {
        $shop = $this->shop('principal');
        $id = $this->product($shop->id);

        foreach ([
            ['role' => 'barber', 'barbershop_id' => $shop->id],
            ['role' => 'admin', 'barbershop_id' => null],
        ] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            $this->get('/products')->assertForbidden();
            $this->get('/products/create')->assertForbidden();
            $this->post('/products', $this->data())->assertForbidden();
            $this->get("/products/$id")->assertForbidden();
            $this->get("/products/$id/edit")->assertForbidden();
            $this->put("/products/$id", $this->data())->assertForbidden();
            $this->delete("/products/$id")->assertForbidden();
        }
    }

    public function test_guests_cannot_use_the_crud(): void
    {
        $shop = $this->shop('principal');
        $id = $this->product($shop->id);

        $this->get('/products')->assertRedirect('/login');
        $this->get('/products/create')->assertRedirect('/login');
        $this->post('/products', $this->data())->assertRedirect('/login');
        $this->get("/products/$id")->assertRedirect('/login');
        $this->get("/products/$id/edit")->assertRedirect('/login');
        $this->put("/products/$id", $this->data())->assertRedirect('/login');
        $this->delete("/products/$id")->assertRedirect('/login');

        $this->assertDatabaseCount('products', 1);
    }

    public function test_invalid_product_data_is_rejected_on_create_and_update(): void
    {
        $shop = $this->shop('principal');
        $id = $this->product($shop->id);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);
        $invalid = ['name' => '', 'brand' => '', 'description' => '', 'price' => '1.234'];
        $fields = ['name', 'brand', 'description', 'price'];

        $this->post('/products', $invalid)->assertSessionHasErrors($fields);
        $this->put("/products/$id", $invalid)->assertSessionHasErrors($fields);
        foreach (['-1', '100000000', '1e2', '35,50', 'abc'] as $price) {
            $this->post('/products', $this->data(['price' => $price]))->assertSessionHasErrors('price');
            $this->put("/products/$id", $this->data(['price' => $price]))->assertSessionHasErrors('price');
        }
        foreach (['name', 'brand'] as $field) {
            $this->post('/products', $this->data([$field => str_repeat('a', 256)]))->assertSessionHasErrors($field);
        }
        $this->get('/products?search='.str_repeat('a', 101))->assertSessionHasErrors('search');
        $this->get('/products?search[]=Pomada')->assertSessionHasErrors('search');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $id, ...$this->data(), 'deleted_at' => null]);
    }

    public function test_price_accepts_zero_and_the_maximum_with_two_decimal_places(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        foreach (['0', '99999999.99'] as $price) {
            $this->from('/inventory')->post('/products', $this->data(['price' => $price]))->assertRedirect('/inventory')->assertSessionHasNoErrors();
            $this->assertDatabaseHas('products', ['barbershop_id' => $shop->id, 'price' => $price]);
        }
    }

    public function test_initial_entry_is_atomic_and_editing_cannot_change_stock_or_cost(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin)->from('/inventory?type=product&search=Pomada')->post('/products', $this->data([
            'initial_entry' => true, 'initial_quantity' => '2', 'initial_total_cost' => '20.00',
        ]))->assertRedirect('/inventory?type=product&search=Pomada');
        $id = DB::table('products')->value('id');
        $this->assertDatabaseHas('products', ['id' => $id, 'stock_quantity' => 2, 'average_cost_milli_cents' => 1000000]);
        $this->assertDatabaseHas('stock_movements', [
            'stockable_id' => $id, 'type' => 'entry', 'quantity' => '2.000',
            'cost_total_cents' => 2000, 'reason' => 'Entrada inicial no cadastro', 'user_id' => $admin->id,
        ]);
        $this->put("/products/$id", $this->data([
            'stock_quantity' => 99, 'average_cost_milli_cents' => 1,
            'initial_entry' => true, 'initial_quantity' => '100', 'initial_total_cost' => '0',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock_quantity' => 2, 'average_cost_milli_cents' => 1000000]);
        foreach ([
            ['initial_quantity' => '1.5', 'initial_total_cost' => '10'],
            ['initial_quantity' => '0', 'initial_total_cost' => '10'],
            ['initial_quantity' => '1'],
            ['initial_quantity' => '1', 'initial_total_cost' => '-1'],
        ] as $entry) {
            $this->postJson('/products', $this->data(['initial_entry' => true, ...$entry]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_archiving_product_requires_zero_stock_and_preserves_ledger(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin)->post('/products', $this->data([
            'initial_entry' => true, 'initial_quantity' => '1', 'initial_total_cost' => '10',
        ]));
        $id = DB::table('products')->value('id');
        $this->deleteJson("/products/$id")->assertUnprocessable()->assertJsonValidationErrors('archive');
        $this->assertDatabaseHas('products', ['id' => $id, 'deleted_at' => null]);
        $this->post('/inventory/movements', [
            'item_type' => 'product', 'item_id' => $id, 'type' => 'exit', 'quantity' => '1', 'reason' => 'Baixa',
        ])->assertSessionHasNoErrors();
        $this->from('/inventory')->delete("/products/$id")->assertRedirect('/inventory');
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->get('/inventory?type=product')->assertInertia(fn (Assert $page) => $page
            ->where('items.total', 0)->where('movements.total', 2));
    }
}
