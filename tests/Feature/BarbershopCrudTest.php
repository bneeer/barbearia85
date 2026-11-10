<?php

namespace Tests\Feature;

use App\Models\Barbershop;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BarbershopCrudTest extends TestCase
{
    use RefreshDatabase;

    private function barbershop(string $name): Barbershop
    {
        return Barbershop::create(['name' => $name, 'slug' => str_replace(' ', '-', strtolower($name))]);
    }

    private function hours(): array
    {
        $hours = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $hours[$day] = ['open' => '09:00', 'close' => '18:00', 'closed' => true];
        }
        $hours['monday']['closed'] = false;

        return $hours;
    }

    private function data(array $attributes = []): array
    {
        return array_merge([
            'name' => 'Barbearia Central',
            'description' => 'Atendimento completo.',
            'address' => 'Rua das Flores, 123',
            'zip_code' => '01310-100',
            'phones' => [],
            'is_open' => true,
            'opening_hours' => $this->hours(),
            'barber_ids' => [],
        ], $attributes);
    }

    public function test_admin_can_create_update_and_view_a_unit_with_its_barbers(): void
    {
        $barber = User::factory()->create(['role' => 'barber']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->get('/barbershops/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Barbershops/Create')->has('barbers', 1)->has('defaultHours.monday'));

        $this->post('/barbershops', $this->data([
            'barber_ids' => [$barber->id],
            'is_open' => false,
            'phones' => ['(11) 99999-9999', '(11) 3333-4444'],
        ]))
            ->assertSessionHasNoErrors()->assertRedirect('/barbershops');

        $barbershop = Barbershop::where('name', 'Barbearia Central')->firstOrFail();
        $this->assertSame('01310100', $barbershop->zip_code);
        $this->assertFalse($barbershop->is_open);
        $this->assertSame(['+5511999999999', '+551133334444'], $barbershop->phones);
        $this->assertDatabaseHas('barbershop_user', [
            'barbershop_id' => $barbershop->id,
            'user_id' => $barber->id,
        ]);
        $this->get("/barbershops/$barbershop->id/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Barbershops/Edit')->where('barberIds.0', $barber->id)
            ->where('barbershop.opening_hours.monday.open', '09:00'));
        $this->get("/barbershops/$barbershop->id")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Barbershops/Show')->where('barbershop.users.0.id', $barber->id)
            ->where('barbershop.phones.0', '+5511999999999'));

        $this->put("/barbershops/$barbershop->id", $this->data([
            'name' => 'Barbearia Centro',
            'barber_ids' => [],
        ]))->assertSessionHasNoErrors()->assertRedirect('/barbershops');
        $this->assertDatabaseHas('barbershops', [
            'id' => $barbershop->id,
            'name' => 'Barbearia Centro',
            'is_open' => true,
        ]);
        $this->assertDatabaseMissing('barbershop_user', [
            'barbershop_id' => $barbershop->id,
            'user_id' => $barber->id,
        ]);
    }

    public function test_barber_crud_keeps_unit_assignments_in_sync(): void
    {
        $primary = $this->barbershop('Principal');
        $secondary = $this->barbershop('Secundária');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $primary->id]);
        $this->actingAs($admin);

        $this->get('/barbers/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('barbershops', 2)->where('defaultBarbershopId', $primary->id));
        $this->post('/barbers', [
            'name' => 'Barbeiro Teste',
            'email' => 'teste@example.com',
            'password' => 'password',
            'barbershop_ids' => [$primary->id, $secondary->id],
        ])->assertSessionHasNoErrors()->assertRedirect('/barbers');

        $barber = User::where('email', 'teste@example.com')->firstOrFail();
        $this->assertDatabaseHas('barbershop_user', ['barbershop_id' => $primary->id, 'user_id' => $barber->id]);
        $this->assertDatabaseHas('barbershop_user', ['barbershop_id' => $secondary->id, 'user_id' => $barber->id]);
        $this->get("/barbers/$barber->id/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('barber.barbershops', 2)->has('barbershops', 2));

        $this->put("/barbers/$barber->id", [
            'name' => $barber->name,
            'email' => $barber->email,
            'barbershop_ids' => [$secondary->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('barbershop_user', ['barbershop_id' => $primary->id, 'user_id' => $barber->id]);
        $this->assertDatabaseHas('barbershop_user', ['barbershop_id' => $secondary->id, 'user_id' => $barber->id]);
    }

    public function test_unit_phones_accept_international_numbers_and_reject_equivalent_duplicates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/barbershops', $this->data([
            'phones' => ['+351 912 345 678', '+1 (202) 555-0123'],
        ]))->assertSessionHasNoErrors();
        $shop = Barbershop::firstOrFail();
        $this->assertSame(['+351912345678', '+12025550123'], $shop->phones);

        $this->put("/barbershops/$shop->id", $this->data([
            'phones' => ['(11) 99999-9999', '+55 11 99999-9999'],
        ]))->assertSessionHasErrors('phones.1');
        $this->put("/barbershops/$shop->id", $this->data([
            'phones' => ['123'],
        ]))->assertSessionHasErrors('phones.0');
        $this->assertSame(['+351912345678', '+12025550123'], $shop->fresh()->phones);
    }

    public function test_unit_schedule_cep_and_barber_ids_are_validated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $invalidHours = $this->hours();
        $invalidHours['monday']['close'] = '09:00';

        $this->post('/barbershops', $this->data([
            'zip_code' => '1234',
            'phones' => ['(11) 99999-9999', '(11) 99999-9999'],
            'opening_hours' => $invalidHours,
            'barber_ids' => [99999],
        ]))->assertSessionHasErrors([
            'zip_code',
            'phones.1',
            'opening_hours.monday.close',
            'barber_ids.0',
        ]);
        $this->assertDatabaseCount('barbershops', 0);
    }

    public function test_admin_can_manage_unit_photos_and_gallery_urls_are_public(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $barbershop = $this->barbershop('Galeria');
        $this->actingAs($admin);

        $this->post("/barbershops/$barbershop->id/photos", [
            'photos' => [
                UploadedFile::fake()->image('fachada.jpg'),
                UploadedFile::fake()->image('interior.png'),
            ],
        ])->assertRedirect("/barbershops/$barbershop->id/edit?tab=photos");

        $photos = $barbershop->photos()->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
            $this->get($photo->url)->assertOk();
        }

        $this->get("/api/barbershops/$barbershop->slug/gallery")
            ->assertOk()->assertJsonCount(2, 'photos')->assertJsonPath('photos.0.url', $photos[0]->url);
        $this->get("/barbershops/$barbershop->id/edit")->assertInertia(fn (Assert $page) => $page
            ->has('barbershop.photos', 2)->where('barbershop.photos.0.url', $photos[0]->url));

        $this->delete("/barbershops/$barbershop->id/photos/{$photos[0]->id}")
            ->assertRedirect("/barbershops/$barbershop->id/edit?tab=photos");
        Storage::disk('public')->assertMissing($photos[0]->path);
        $this->assertDatabaseMissing('barbershop_photos', ['id' => $photos[0]->id]);
    }

    public function test_photo_upload_validates_images_and_rejects_non_admins_and_foreign_photos(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $barbershop = $this->barbershop('Unidade A');
        $otherBarbershop = $this->barbershop('Unidade B');
        $photo = $barbershop->photos()->create(['path' => 'barbershop-photos/foto.jpg']);
        Storage::disk('public')->put($photo->path, 'image');

        $this->actingAs(User::factory()->create(['role' => 'barber']))
            ->post("/barbershops/$barbershop->id/photos", [
                'photos' => [UploadedFile::fake()->image('foto.jpg')],
            ])->assertForbidden();

        $this->actingAs($admin)
            ->post("/barbershops/$barbershop->id/photos", [
                'photos' => [UploadedFile::fake()->create('arquivo.txt', 10, 'text/plain')],
            ])->assertSessionHasErrors('photos.0');
        $this->delete("/barbershops/$otherBarbershop->id/photos/$photo->id")->assertNotFound();
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_only_admins_can_manage_units_and_units_with_business_data_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $barbershop = $this->barbershop('Unidade');
        $this->actingAs(User::factory()->create(['role' => 'barber']))
            ->get('/barbershops')->assertForbidden();

        $service = Service::create([
            'barbershop_id' => $barbershop->id,
            'name' => 'Corte',
            'description' => 'Corte padrão',
            'duration' => 30,
            'price' => '10.00',
        ]);
        $this->actingAs($admin)->delete("/barbershops/$barbershop->id")
            ->assertSessionHasErrors('barbershop');
        $this->assertDatabaseHas('services', ['id' => $service->id]);

        $empty = $this->barbershop('Vazia');
        $this->delete("/barbershops/$empty->id")->assertRedirect('/barbershops');
        $this->assertDatabaseMissing('barbershops', ['id' => $empty->id]);
    }
}
