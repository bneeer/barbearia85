<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BarberCrudTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $name): Barbershop
    {
        return Barbershop::create(['name' => $name, 'slug' => $name]);
    }

    private function service(Barbershop $shop, string $name, bool $active = true): Service
    {
        return Service::create([
            'barbershop_id' => $shop->id,
            'name' => $name,
            'description' => 'Descrição',
            'duration' => 30,
            'price' => '10.00',
            'is_active' => $active,
        ]);
    }

    private function data(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Barbeiro',
            'email' => 'barbeiro@example.com',
            'password' => 'password',
            'phone' => '(11) 99999-9999',
            'service_ids' => [],
        ], $attributes);
    }

    public function test_admin_can_create_update_and_clear_barber_services(): void
    {
        Storage::fake('public');
        $shop = $this->shop('principal');
        $first = $this->service($shop, 'Corte');
        $second = $this->service($shop, 'Barba', false);
        $otherShop = $this->shop('outra');
        $other = $this->service($otherShop, 'Outro');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        $this->get('/barbers/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Barbers/Create')->has('services', 2)->where('services.0.name', 'Barba')
            ->where('services.0.is_active', false));

        $this->post('/barbers', $this->data([
            'service_ids' => [$first->id, $second->id],
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]))->assertSessionHasNoErrors()->assertRedirect('/barbers');
        $barber = User::where('email', 'barbeiro@example.com')->firstOrFail();
        $this->assertSame($shop->id, $barber->barbershop_id);
        $this->assertSame('+5511999999999', $barber->phone);
        $this->assertDatabaseCount('barber_service', 2);
        Storage::disk('public')->assertExists($barber->photo_path);

        $this->get("/barbers/$barber->id/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Barbers/Edit')->has('barber.services', 2)->has('services', 2)
            ->where('barber.avatar', $barber->avatar));
        $this->get("/barbers/$barber->id")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Barbers/Show')->where('barber.phone', '+5511999999999')->has('barber.services', 2));
        $this->get('/barbers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('barbers.data.0.avatar', $barber->avatar)->where('barbers.data.0.phone', $barber->phone));

        $this->put("/barbers/$barber->id", $this->data([
            'password' => 'password',
            'phone' => '+44 20 7946 0018',
            'service_ids' => [$second->id],
        ]))->assertSessionHasNoErrors()->assertRedirect('/barbers');
        $this->assertDatabaseMissing('barber_service', ['user_id' => $barber->id, 'service_id' => $first->id]);
        $this->assertDatabaseHas('barber_service', ['user_id' => $barber->id, 'service_id' => $second->id]);
        $this->assertDatabaseMissing('barber_service', ['user_id' => $barber->id, 'service_id' => $other->id]);
        $this->assertSame('+442079460018', $barber->fresh()->phone);

        $data = $this->data();
        unset($data['service_ids'], $data['password']);
        $data['phone'] = '';
        $this->put("/barbers/$barber->id", $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('barber_service', 0);
        $this->assertNull($barber->fresh()->phone);
    }

    public function test_photo_is_shared_between_profile_and_admin_forms(): void
    {
        Storage::fake('public');
        $shop = $this->shop('principal');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);

        $this->actingAs($barber)->patch('/settings/profile', [
            'name' => $barber->name, 'email' => $barber->email,
            'photo' => UploadedFile::fake()->image('profile.jpg'),
        ])->assertSessionHasNoErrors();
        $profilePath = $barber->fresh()->photo_path;
        $profileAvatar = $barber->fresh()->avatar;
        $this->actingAs($admin)->get("/barbers/$barber->id/edit")->assertInertia(fn (Assert $page) => $page
            ->where('barber.avatar', $profileAvatar));

        $this->post("/barbers/$barber->id", [
            '_method' => 'PUT', 'name' => $barber->name, 'email' => $barber->email,
            'photo' => UploadedFile::fake()->image('admin.png'),
        ])->assertSessionHasNoErrors()->assertRedirect('/barbers');
        $barber->refresh();
        Storage::disk('public')->assertMissing($profilePath);
        Storage::disk('public')->assertExists($barber->photo_path);
        $this->get($barber->avatar)->assertOk();
        $this->actingAs($barber)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.avatar', $barber->avatar));
    }

    public function test_rejects_foreign_deleted_duplicate_and_invalid_services_and_invalid_contact_data(): void
    {
        Storage::fake('public');
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $service = $this->service($shop, 'Corte');
        $foreign = $this->service($other, 'Outro');
        $deleted = $this->service($shop, 'Excluído');
        $deleted->delete();
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $barber->services()->attach($service);
        $this->actingAs($admin);

        foreach ([$foreign->id, $deleted->id, 99999] as $id) {
            $this->post('/barbers', $this->data(['service_ids' => [$id]]))->assertSessionHasErrors('service_ids.0');
            $this->put("/barbers/$barber->id", $this->data(['service_ids' => [$id]]))->assertSessionHasErrors('service_ids.0');
        }
        $this->post('/barbers', $this->data(['service_ids' => [$service->id, $service->id]]))
            ->assertSessionHasErrors('service_ids.0');
        $this->post('/barbers', $this->data(['phone' => str_repeat('1', 31), 'photo' => UploadedFile::fake()->create('file.txt', 10, 'text/plain')]))
            ->assertSessionHasErrors(['phone', 'photo']);
        foreach (['123', '+999123456789', '+5511999999999 ext. 1'] as $phone) {
            $this->post('/barbers', $this->data(['phone' => $phone]))->assertSessionHasErrors('phone');
        }
        $this->post('/barbers', $this->data(['photo' => UploadedFile::fake()->image('big.jpg')->size(2049)]))
            ->assertSessionHasErrors('photo');
        $this->assertDatabaseHas('barber_service', ['user_id' => $barber->id, 'service_id' => $service->id]);
        $this->assertSame(1, DB::table('barber_service')->count());
    }

    public function test_admin_cannot_manage_other_shops_users_or_non_barbers(): void
    {
        $shop = $this->shop('principal');
        $other = $this->shop('outra');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $foreign = User::factory()->create(['role' => 'barber', 'barbershop_id' => $other->id]);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $this->actingAs($admin);

        foreach ([$foreign, $otherAdmin] as $user) {
            $this->get("/barbers/$user->id")->assertNotFound();
            $this->get("/barbers/$user->id/edit")->assertNotFound();
            $this->put("/barbers/$user->id", $this->data())->assertNotFound();
            $this->delete("/barbers/$user->id")->assertNotFound();
        }
        $this->actingAs($foreign)->get('/barbers')->assertForbidden();
        $this->get("/barbers/$foreign->id")->assertForbidden();
        $this->post('/barbers', $this->data())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barbershop_id' => null]))
            ->get('/barbers')->assertForbidden();
    }

    public function test_deleting_a_barber_removes_the_photo_and_service_links(): void
    {
        Storage::fake('public');
        $shop = $this->shop('principal');
        $service = $this->service($shop, 'Corte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $photo = UploadedFile::fake()->image('photo.jpg')->store('barber-photos', 'public');
        $barber->photo_path = $photo;
        $barber->save();
        $barber->services()->attach($service);

        $this->actingAs($admin)->delete("/barbers/$barber->id")->assertRedirect('/barbers');

        Storage::disk('public')->assertMissing($photo);
        $this->assertDatabaseMissing('barber_service', ['user_id' => $barber->id]);
        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }
}
