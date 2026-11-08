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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_create_view_edit_update_and_delete_a_product(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get('/products/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Products/Create'));
        $this->post('/products', $this->data(['barbershop_id' => $other->id]))->assertRedirect('/products');
        $this->assertDatabaseHas('products', ['barbershop_id' => $shop->id, ...$this->data()]);
        $id = DB::table('products')->value('id');

        $this->get("/products/$id")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')->where('product.name', 'Pomada')
            ->where('product.brand', 'Marca A')->where('product.price', '35.50')
            ->where('product.description', 'Pomada modeladora para cabelo'));
        $this->get("/products/$id/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Products/Edit')->where('product.id', $id));
        $updated = $this->data([
            'name' => 'Óleo para barba',
            'brand' => 'Marca B',
            'description' => 'Óleo hidratante',
            'price' => '42.90',
        ]);
        $this->put("/products/$id", [...$updated, 'barbershop_id' => $other->id])->assertRedirect('/products');
        $this->assertDatabaseHas('products', ['id' => $id, 'barbershop_id' => $shop->id, ...$updated]);

        $this->delete("/products/$id")->assertRedirect('/products');
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->get("/products/$id")->assertNotFound();
        $this->get("/products/$id/edit")->assertNotFound();
        $this->put("/products/$id", $this->data())->assertNotFound();
        $this->delete("/products/$id")->assertNotFound();
        $this->get('/products')->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
    }

    public function test_list_searches_name_and_brand_and_paginates_only_products_from_the_admins_shop(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        for ($i = 0; $i < 11; $i++) {
            $this->product($shop->id);
        }
        $this->product($other->id);
        $this->product($shop->id, ['name' => 'Óleo', 'brand' => 'Marca B']);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get('/products?search=Pomada')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Products/Index')
                ->where('products.total', 11)->has('products.data', 10)->where('filters.search', 'Pomada'));
        $this->get('/products?search=Pomada&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
        $this->get('/products?search=Marca%20A')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 11));
        $this->get('/products?search=inexistente')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 0));
        $this->get('/products')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.total', 12)->where('filters.search', ''));
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
            $this->post('/products', $this->data(['price' => $price]))->assertRedirect('/products')->assertSessionHasNoErrors();
            $this->assertDatabaseHas('products', ['barbershop_id' => $shop->id, 'price' => $price]);
        }
    }
}
