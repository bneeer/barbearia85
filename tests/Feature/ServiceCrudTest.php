<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceCrudTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $slug): Barbershop
    {
        return Barbershop::create(['name' => $slug, 'slug' => $slug]);
    }

    private function service(int $shopId, array $attributes = []): int
    {
        return DB::table('services')->insertGetId(array_merge([
            'barbershop_id' => $shopId,
            'name' => 'Corte',
            'description' => 'Corte de cabelo',
            'duration' => 30,
            'price' => '50.00',
            'is_active' => true,
            'created_at' => now(new \DateTimeZone('America/Sao_Paulo')),
            'updated_at' => now(new \DateTimeZone('America/Sao_Paulo')),
        ], $attributes));
    }

    private function data(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Barba',
            'description' => 'Aparar barba',
            'duration' => 20,
            'price' => '35.50',
            'is_active' => false,
        ], $attributes);
    }

    public function test_admin_can_create_view_edit_update_and_delete_a_service(): void
    {
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get('/services/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Services/Create'));
        $this->post('/services', $this->data(['barbershop_id' => 999]))->assertRedirect('/services');
        $this->assertDatabaseHas('services', ['barbershop_id' => $shop->id, 'name' => 'Barba', 'is_active' => false]);
        $id = DB::table('services')->where('name', 'Barba')->value('id');

        $this->get("/services/$id")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Services/Show')->where('service.is_active', false)->where('service.price', '35.50'));
        $this->get("/services/$id/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Services/Edit'));
        $this->put("/services/$id", $this->data(['name' => 'Corte e barba', 'is_active' => true]))
            ->assertRedirect('/services');
        $this->assertDatabaseHas('services', ['id' => $id, 'name' => 'Corte e barba', 'is_active' => true]);
        $this->delete("/services/$id")->assertRedirect('/services');
        $this->assertSoftDeleted('services', ['id' => $id]);
        $this->get("/services/$id")->assertNotFound();
    }

    public function test_list_filters_and_paginates_only_services_from_the_admins_shop(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        for ($i = 0; $i < 11; $i++) {
            $this->service($shop->id);
        }
        $this->service($other->id);
        $this->service($shop->id, ['name' => 'Barba']);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);

        $this->actingAs($admin)->get('/services?search=Corte')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Services/Index')
                ->where('services.total', 11)->has('services.data', 10)->where('filters.search', 'Corte'));
        $this->get('/services?search=Corte&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('services.data', 1));
        $this->get('/services?search=inexistente')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('services.total', 0));
    }

    public function test_admin_cannot_access_or_modify_another_shops_service(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $id = $this->service($other->id);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get("/services/$id")->assertNotFound();
        $this->get("/services/$id/edit")->assertNotFound();
        $this->put("/services/$id", $this->data())->assertNotFound();
        $this->delete("/services/$id")->assertNotFound();
        $this->assertDatabaseHas('services', ['id' => $id, 'name' => 'Corte', 'deleted_at' => null]);
    }

    public function test_only_admins_with_a_shop_can_use_the_crud(): void
    {
        $shop = $this->shop('principal');
        $id = $this->service($shop->id);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $this->actingAs($barber);

        $this->get('/services')->assertForbidden();
        $this->get('/services/create')->assertForbidden();
        $this->post('/services', $this->data())->assertForbidden();
        $this->get("/services/$id")->assertForbidden();
        $this->get("/services/$id/edit")->assertForbidden();
        $this->put("/services/$id", $this->data())->assertForbidden();
        $this->delete("/services/$id")->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => null]);
        $this->actingAs($admin)->get('/services')->assertForbidden();
        $this->post('/services', $this->data())->assertForbidden();
    }

    public function test_invalid_service_data_is_rejected_on_create_and_update(): void
    {
        $shop = $this->shop('principal');
        $id = $this->service($shop->id);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);
        $invalid = ['name' => '', 'description' => '', 'price' => '1.234', 'duration' => 0, 'is_active' => 'invalid'];
        $fields = ['name', 'description', 'price', 'duration', 'is_active'];

        $this->post('/services', $invalid)->assertSessionHasErrors($fields);
        $this->put("/services/$id", $invalid)->assertSessionHasErrors($fields);
        $this->post('/services', $this->data(['price' => '-1']))->assertSessionHasErrors('price');
        $this->post('/services', $this->data(['price' => '100000000']))->assertSessionHasErrors('price');
        $this->post('/services', $this->data(['duration' => '1.5']))->assertSessionHasErrors('duration');
        $this->assertDatabaseHas('services', ['id' => $id, 'name' => 'Corte', 'deleted_at' => null]);
    }

    public function test_services_are_created_with_description_and_active_by_default(): void
    {
        $shop = $this->shop('principal');
        $id = DB::table('services')->insertGetId([
            'barbershop_id' => $shop->id, 'name' => 'Corte',
            'description' => 'Corte de cabelo', 'duration' => 30, 'price' => '50.00',
        ]);

        $this->assertTrue(Schema::hasColumn('services', 'description'));
        $this->assertDatabaseHas('services', ['id' => $id, 'description' => 'Corte de cabelo', 'is_active' => true]);
    }
}
