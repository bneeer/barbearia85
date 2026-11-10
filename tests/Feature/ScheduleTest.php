<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Barbershop $shop;

    private Barbershop $other;

    private User $admin;

    private User $barber;

    private User $colleague;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'America/Sao_Paulo')->utc());
        foreach (['centro', 'outra'] as $slug) {
            $shop = Barbershop::create([
                'name' => $slug, 'slug' => $slug, 'timezone' => 'America/Sao_Paulo', 'is_open' => true,
                'opening_hours' => array_fill_keys(
                    ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                    ['open' => '09:00', 'close' => '20:00', 'closed' => false],
                ),
            ]);
            $slug === 'centro' ? $this->shop = $shop : $this->other = $shop;
        }
        $this->admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $this->other->id]);
        $this->barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $this->shop->id]);
        $this->colleague = User::factory()->create(['role' => 'barber', 'barbershop_id' => $this->shop->id]);
        $this->barber->barbershops()->attach($this->shop);
        $this->colleague->barbershops()->attach($this->shop);
        $this->service = Service::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Corte', 'description' => 'Corte completo', 'duration' => 40,
            'price' => 50, 'is_active' => true,
        ]);
        $this->barber->services()->attach($this->service);
        $this->colleague->services()->attach($this->service);
    }

    private function appointment(?User $barber = null, ?Barbershop $shop = null, string $time = '09:00'): Appointment
    {
        $shop ??= $this->shop;
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Cliente', 'email' => 'cliente@example.com', 'phone' => '11999999999',
        ]);
        $start = CarbonImmutable::parse('2026-11-09 '.$time, $shop->timezone)->utc();

        return Appointment::create([
            'barbershop_id' => $shop->id, 'user_id' => ($barber ?? $this->barber)->id,
            'client_id' => $client->id, 'service_id' => $this->service->id,
            'start_time' => $start, 'end_time' => $start->addMinutes(40), 'status' => 'scheduled',
        ]);
    }

    private function events(array $overrides = [])
    {
        return $this->getJson('/schedule/events?'.http_build_query([
            'barbershop_id' => $this->shop->id,
            'start' => '2026-11-09T00:00:00-03:00', 'end' => '2026-11-10T00:00:00-03:00',
            ...$overrides,
        ]));
    }

    private function version(Appointment $appointment): string
    {
        return hash('sha256', implode('|', [
            $appointment->start_time->toIso8601String(), $appointment->end_time->toIso8601String(),
            $appointment->status, $appointment->updated_at->toIso8601String(),
        ]));
    }

    private function update(Appointment $appointment, array $overrides = [])
    {
        return $this->patchJson('/schedule/appointments/'.$appointment->id, [
            'barbershop_id' => $this->shop->id, 'status' => 'scheduled', 'version' => $this->version($appointment),
            'start' => '2026-11-09T10:10',             'end' => '2026-11-09T10:50', ...$overrides,
        ]);
    }

    private function block(array $overrides = []): array
    {
        return [
            'barbershop_id' => $this->shop->id, 'barber_ids' => [$this->barber->id],
            'start' => '2026-11-09T09:00', 'end' => '2026-11-09T09:40', 'reason' => 'Pausa', ...$overrides,
        ];
    }

    public function test_catalog_and_event_feed_enforce_role_and_unit_access_without_tenant_scope_leaks(): void
    {
        $own = $this->appointment();
        $this->appointment($this->colleague);
        $this->appointment(shop: $this->other);
        $this->actingAs($this->admin)->get('/schedule')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Schedule/Index')->has('barbershops', 2)->where('canManageAppointments', true)
            ->has('barbershops.0.services', 1)->where('barbershops.0.barbers.0.service_ids.0', $this->service->id));
        $this->events()->assertOk()->assertJsonCount(2)->assertJsonPath('0.extendedProps.client.email', 'cliente@example.com');
        $this->events(['barber_id' => $this->colleague->id])->assertOk()->assertJsonCount(1);
        $this->actingAs($this->barber)->get('/schedule')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('barbershops', 1)->has('barbershops.0.barbers', 1)->where('canManageAppointments', false));
        $this->events()->assertOk()->assertJsonCount(1)->assertJsonPath('0.extendedProps.recordId', $own->id)
            ->assertJsonPath('0.editable', false);
        $this->events(['barber_id' => $this->colleague->id])->assertOk()->assertJsonCount(1)->assertJsonPath('0.extendedProps.barberId', $this->barber->id);
        $this->events(['barbershop_id' => $this->other->id])->assertForbidden();
        $this->update($own)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'client']))->get('/schedule')->assertForbidden();
    }

    public function test_admin_reschedules_and_cancels_with_service_duration_and_stale_edit_detection(): void
    {
        $appointment = $this->appointment();
        Notification::create([
            'client_id' => $appointment->client_id, 'appointment_id' => $appointment->id,
            'type' => 'email', 'template' => 'booking_confirmation', 'status' => 'pending',
            'payload' => ['starts_at' => '2026-11-09T09:00:00-03:00'],
        ]);
        $this->actingAs($this->admin);
        $this->update($appointment, ['end' => '2026-11-09T11:00'])->assertUnprocessable();
        $this->update($appointment)->assertOk();
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id, 'start_time' => '2026-11-09 13:10:00', 'end_time' => '2026-11-09 13:50:00',
        ]);
        $this->assertSame('2026-11-09T10:10:00-03:00', Notification::first()->payload['starts_at']);
        $this->update($appointment)->assertConflict();
        $appointment->refresh();
        $this->update($appointment, ['status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame('booking_cancellation', Notification::first()->template);
        $this->events()->assertOk()->assertJsonCount(0);
        $this->postJson('/schedule/blocks', $this->block(['start' => '2026-11-09T10:10', 'end' => '2026-11-09T11:00']))->assertCreated();
    }

    public function test_admin_creates_manual_booking_with_availability_and_notification_snapshots(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/schedule/appointments', [
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente pelo WhatsApp',
            'email' => 'whatsapp@example.com',
            'phone' => '(11) 99999-1234',
            'notes' => 'Prefere corte mais curto.',
            'service_id' => $this->service->id,
            'barber_id' => $this->barber->id,
            'date' => '2026-11-09',
            'time' => '09:00',
        ])->assertCreated()->assertJsonStructure(['id']);

        $this->assertDatabaseCount('appointments', 1);
        $appointmentId = $response->json('id');
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'barbershop_id' => $this->shop->id]);
        $appointment = DB::table('appointments')->where('id', $appointmentId)->first();
        $this->assertSame('2026-11-09 12:00:00', $appointment->start_time);
        $this->assertSame('2026-11-09 12:40:00', $appointment->end_time);
        $this->assertSame('Prefere corte mais curto.', $appointment->notes);
        $client = DB::table('clients')->where('id', $appointment->client_id)->first();
        $this->assertSame('Cliente pelo WhatsApp', $client->name);
        $this->assertSame('Prefere corte mais curto.', $client->notes);
        $this->assertDatabaseCount('notifications', 2);
        foreach (Notification::all() as $notification) {
            $this->assertSame($appointmentId, $notification->appointment_id);
            $this->assertSame('booking_confirmation', $notification->template);
            $this->assertSame('pending', $notification->status);
            $this->assertSame('2026-11-09T09:00:00-03:00', $notification->payload['starts_at']);
            $this->assertSame('Corte', $notification->payload['service']);
            $this->assertSame('Cliente pelo WhatsApp', $notification->payload['name']);
        }
        $this->events()->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.extendedProps.client.name', 'Cliente pelo WhatsApp');
    }

    public function test_calendar_selected_duration_must_fit_service_and_longer_selection_keeps_remaining_time_free(): void
    {
        $this->actingAs($this->admin);
        $payload = [
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente do calendário',
            'email' => 'calendario@example.com',
            'phone' => '11999991234',
            'service_id' => $this->service->id,
            'barber_id' => $this->barber->id,
            'date' => '2026-11-09',
            'time' => '09:00',
        ];

        $this->postJson('/schedule/appointments', [...$payload, 'selected_duration' => 39])
            ->assertUnprocessable()->assertJsonValidationErrors('selected_duration');
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('clients', 0);

        $equalDuration = $this->postJson('/schedule/appointments', [
            ...$payload, 'selected_duration' => 40,
        ])->assertCreated();
        $this->assertDatabaseHas('appointments', [
            'id' => $equalDuration->json('id'),
            'start_time' => '2026-11-09 12:00:00',
            'end_time' => '2026-11-09 12:40:00',
        ]);

        $longerSelection = $this->postJson('/schedule/appointments', [
            ...$payload, 'time' => '09:40', 'selected_duration' => 60,
        ])->assertCreated();
        $this->assertDatabaseHas('appointments', [
            'id' => $longerSelection->json('id'),
            'start_time' => '2026-11-09 12:40:00',
            'end_time' => '2026-11-09 13:20:00',
        ]);
        $this->assertDatabaseCount('appointments', 2);
        $this->assertDatabaseCount('notifications', 4);
    }

    public function test_manual_booking_combines_services_and_requires_the_selected_barber_to_offer_all_of_them(): void
    {
        $this->actingAs($this->admin);
        $secondService = Service::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Barba',
            'description' => 'Barba completa', 'duration' => 60, 'price' => 35, 'is_active' => true,
        ]);
        $this->barber->services()->attach($secondService);
        $payload = [
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente do calendário',
            'email' => 'calendario@example.com',
            'phone' => '11999991234',
            'service_ids' => [$this->service->id, $secondService->id],
            'barber_id' => $this->barber->id,
            'date' => '2026-11-09',
            'time' => '09:00',
        ];

        $this->postJson('/schedule/appointments', [...$payload, 'selected_duration' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('selected_duration');
        $this->postJson('/schedule/appointments', [...$payload, 'barber_id' => $this->colleague->id])
            ->assertUnprocessable()->assertJsonValidationErrors('service_ids');
        $response = $this->postJson('/schedule/appointments', [...$payload, 'selected_duration' => 120])
            ->assertCreated();

        $appointment = Appointment::withoutGlobalScopes()->findOrFail($response->json('id'));
        $this->assertDatabaseHas('appointment_service', [
            'appointment_id' => $appointment->id, 'service_id' => $this->service->id,
        ]);
        $this->assertDatabaseHas('appointment_service', [
            'appointment_id' => $appointment->id, 'service_id' => $secondService->id,
        ]);
        $this->assertSame('2026-11-09 13:40:00', $appointment->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('Corte + Barba', Notification::firstOrFail()->payload['service']);
        $this->assertSame(100, Notification::firstOrFail()->payload['duration']);
        $this->events()->assertOk()->assertJsonPath('0.extendedProps.services.0.name', 'Corte')
            ->assertJsonPath('0.extendedProps.services.1.name', 'Barba');
    }

    public function test_manual_booking_requires_admin_and_valid_unit_barber_service_and_contact_data(): void
    {
        $payload = [
            'barbershop_id' => $this->shop->id, 'name' => 'Cliente', 'email' => 'cliente@example.com',
            'phone' => '(11) 99999-1234', 'service_id' => $this->service->id,
            'barber_id' => $this->barber->id, 'date' => '2026-11-09', 'time' => '09:00',
        ];
        $this->actingAs($this->barber)->postJson('/schedule/appointments', $payload)->assertForbidden();
        $this->actingAs($this->admin);
        $this->postJson('/schedule/appointments', [...$payload, 'barbershop_id' => 999])->assertNotFound();
        $this->postJson('/schedule/appointments', [...$payload, 'barber_id' => $this->colleague->id, 'service_id' => 999])->assertUnprocessable();
        $otherShopService = Service::create([
            'barbershop_id' => $this->other->id, 'name' => 'Barba',
            'description' => 'Barba completa', 'duration' => 40, 'price' => 35, 'is_active' => true,
        ]);
        $this->postJson('/schedule/appointments', [...$payload, 'service_id' => $otherShopService->id])->assertUnprocessable();
        $this->barber->services()->detach($this->service);
        $this->postJson('/schedule/appointments', $payload)->assertUnprocessable()->assertJsonValidationErrors('service_id');
        $this->barber->services()->attach($this->service);
        foreach ([
            ['name' => ''], ['email' => 'inválido'], ['phone' => '(())------'],
            ['date' => '2026-12-25'], ['date' => '2027-01-01'],
            ['time' => '09:10'], ['time' => '08:00'], ['service_id' => null], ['barber_id' => null],
        ] as $invalid) {
            $this->postJson('/schedule/appointments', [...$payload, ...$invalid])->assertUnprocessable();
        }
        $this->shop->update(['is_open' => false]);
        $this->postJson('/schedule/appointments', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_manual_booking_rechecks_conflicts_blocks_and_professional_working_hours(): void
    {
        $this->actingAs($this->admin);
        $payload = [
            'barbershop_id' => $this->shop->id, 'name' => 'Cliente', 'email' => 'cliente@example.com',
            'phone' => '11999991234', 'service_id' => $this->service->id,
            'barber_id' => $this->barber->id, 'date' => '2026-11-09', 'time' => '09:00',
        ];
        $this->appointment(shop: $this->other);
        $this->postJson('/schedule/appointments', $payload)->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('clients', 1);
        Appointment::withoutGlobalScope(BarbershopScope::class)->delete();
        BlockedTime::create([
            'user_id' => $this->barber->id,
            'start_datetime' => CarbonImmutable::parse('2026-11-09 09:00', $this->shop->timezone)->utc(),
            'end_datetime' => CarbonImmutable::parse('2026-11-09 09:40', $this->shop->timezone)->utc(),
        ]);
        $this->postJson('/schedule/appointments', $payload)->assertUnprocessable();
        BlockedTime::query()->delete();
        WorkingHour::create([
            'user_id' => $this->barber->id, 'day_of_week' => 1,
            'start_time' => '10:00', 'end_time' => '12:00',
        ]);
        $this->postJson('/schedule/appointments', $payload)->assertUnprocessable();
        $this->postJson('/schedule/appointments', [...$payload, 'time' => '10:20'])->assertCreated();
        $this->assertSame(1, Appointment::withoutGlobalScopes()->whereNull('deleted_at')->count());
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_rescheduling_rejects_conflicts_in_other_units_blocks_invalid_duration_and_working_hours(): void
    {
        $appointment = $this->appointment();
        $other = $this->appointment(shop: $this->other, time: '10:20');
        $this->actingAs($this->admin);
        $this->update($appointment)->assertUnprocessable()->assertJsonValidationErrors('start');
        $other->delete();
        foreach ([
            ['end' => '2026-11-09T10:30'], ['start' => '2026-11-09T08:00'],
            ['end' => '2026-11-10T11:00'], ['end' => '2026-11-09T21:00'],
            ['start' => '2026-02-30T10:00'], ['end' => '2026-11-09T10:00'],
        ] as $overrides) {
            $this->update($appointment, $overrides)->assertUnprocessable();
        }
        $block = BlockedTime::create([
            'user_id' => $this->barber->id, 'start_datetime' => '2026-11-09 13:00', 'end_datetime' => '2026-11-09 14:00',
        ]);
        $this->update($appointment)->assertUnprocessable();
        $block->delete();
        WorkingHour::create(['user_id' => $this->barber->id, 'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '18:00']);
        $this->update($appointment)->assertUnprocessable();
        $this->assertSame('2026-11-09 12:00:00', $appointment->fresh()->start_time->format('Y-m-d H:i:s'));
    }

    public function test_barber_manages_only_own_blocks_and_blocks_affect_public_booking(): void
    {
        $this->actingAs($this->barber);
        $this->postJson('/schedule/blocks', $this->block(['barber_ids' => [$this->colleague->id]]))->assertForbidden();
        $this->postJson('/schedule/blocks', $this->block())->assertCreated();
        $block = BlockedTime::firstOrFail();
        $this->events()->assertOk()->assertJsonPath('0.extendedProps.kind', 'block');
        $slots = $this->getJson('/agendar/disponibilidade?'.http_build_query([
            'barbershop_id' => $this->shop->id, 'barber_id' => $this->barber->id,
            'service_id' => $this->service->id, 'date' => '2026-11-09',
        ]))->assertOk()->json('slots');
        $this->assertNotContains('09:00', array_column($slots, 'time'));
        $this->assertContains('09:40', array_column($slots, 'time'));
        $this->patchJson('/schedule/blocks/'.$block->id, $this->block([
            'start' => '2026-11-09T13:00', 'end' => '2026-11-09T15:30',
        ]))->assertOk();
        $this->actingAs($this->colleague)->deleteJson('/schedule/blocks/'.$block->id, ['barbershop_id' => $this->shop->id])->assertForbidden();
        $this->actingAs($this->barber)->deleteJson('/schedule/blocks/'.$block->id, ['barbershop_id' => $this->shop->id])->assertOk();
        $this->assertDatabaseCount('blocked_times', 0);
    }

    public function test_full_day_and_multi_professional_blocks_are_atomic_and_global(): void
    {
        $this->actingAs($this->admin);
        $appointment = $this->appointment($this->colleague, $this->other);
        $data = $this->block([
            'barber_ids' => [$this->barber->id, $this->colleague->id],
            'full_day' => true, 'date' => '2026-11-09',
        ]);
        $this->postJson('/schedule/blocks', $data)->assertUnprocessable();
        $this->assertDatabaseCount('blocked_times', 0);
        $appointment->delete();
        $this->postJson('/schedule/blocks', $data)->assertCreated();
        $this->assertDatabaseCount('blocked_times', 2);
        $this->assertDatabaseHas('blocked_times', [
            'user_id' => $this->barber->id, 'start_datetime' => '2026-11-09 11:00:00', 'end_datetime' => '2026-11-10 03:00:00',
        ]);
        $this->barber->barbershops()->attach($this->other);
        $this->events(['barbershop_id' => $this->other->id])->assertOk()->assertJsonCount(1);
        $this->postJson('/schedule/blocks', $data)->assertUnprocessable();
    }

    public function test_arbitrary_multi_day_blocks_adjacency_and_feed_range_validation(): void
    {
        $this->actingAs($this->admin);
        $this->appointment();
        $this->postJson('/schedule/blocks', $this->block([
            'start' => '2026-11-09T09:40', 'end' => '2026-11-12T17:13',
        ]))->assertCreated();
        $this->postJson('/schedule/blocks', $this->block([
            'start' => '2026-11-12T17:13', 'end' => '2026-11-12T17:14',
        ]))->assertCreated();
        $this->events(['end' => '2027-01-20T00:00:00-03:00'])->assertUnprocessable();
        $this->events(['end' => '2026-11-08'])->assertUnprocessable();
        $this->events(['barber_id' => $this->admin->id])->assertNotFound();
        $this->postJson('/schedule/blocks', $this->block(['full_day' => true, 'date' => null]))->assertUnprocessable();
    }

    public function test_full_day_uses_unit_timezone_and_daylight_saving_day_length(): void
    {
        $this->shop->update(['timezone' => 'America/Los_Angeles']);
        $this->actingAs($this->barber)->postJson('/schedule/blocks', $this->block([
            'full_day' => true, 'date' => '2027-03-14',
        ]))->assertCreated();
        $block = BlockedTime::firstOrFail();
        $this->assertSame(23.0, $block->start_datetime->diffInHours($block->end_datetime));
        $this->postJson('/schedule/blocks', $this->block([
            'start' => '2027-03-14T02:30', 'end' => '2027-03-14T04:00',
        ]))->assertUnprocessable();
    }

    public function test_blocks_reject_past_intervals_on_create_and_update(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/schedule/blocks', $this->block())->assertCreated();
        $block = BlockedTime::firstOrFail();
        foreach ([
            ['start' => '2026-11-08T09:00', 'end' => '2026-11-08T10:00'],
            ['start' => '2026-11-09T07:59', 'end' => '2026-11-09T10:00'],
            ['start' => '2026-11-09T08:00', 'end' => '2026-11-09T10:00'],
            ['full_day' => true, 'date' => '2026-11-08'],
        ] as $invalid) {
            $payload = $this->block($invalid);
            $this->postJson('/schedule/blocks', $payload)->assertUnprocessable();
            $this->patchJson('/schedule/blocks/'.$block->id, $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('blocked_times', 1);
        $this->assertSame('2026-11-09 12:00:00', $block->fresh()->start_datetime->format('Y-m-d H:i:s'));
    }

    public function test_closing_today_only_blocks_remaining_time_in_the_unit_timezone(): void
    {
        $this->shop->update(['timezone' => 'America/Los_Angeles']);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 23:30', $this->shop->timezone)->utc());
        $this->actingAs($this->barber);
        $this->postJson('/schedule/blocks', $this->block([
            'full_day' => true, 'date' => '2026-11-08',
        ]))->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson('/schedule/blocks', $this->block([
            'full_day' => true, 'date' => '2026-11-09',
        ]))->assertCreated();
        $block = BlockedTime::firstOrFail();
        $this->assertSame('2026-11-10 07:30:00', $block->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 08:00:00', $block->end_datetime->format('Y-m-d H:i:s'));
        $this->assertSame(30.0, $block->start_datetime->diffInMinutes($block->end_datetime));
    }

    public function test_guest_cannot_access_or_mutate_schedule(): void
    {
        $this->get('/schedule')->assertRedirect('/login');
        $this->events()->assertUnauthorized();
        $this->postJson('/schedule/blocks', $this->block())->assertUnauthorized();
    }

    public function test_manual_booking_rejects_past_dates_elapsed_slots_and_the_current_instant(): void
    {
        $this->shop->update(['timezone' => 'America/Los_Angeles']);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:40', $this->shop->timezone)->utc());
        $this->actingAs($this->admin);
        $payload = [
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente', 'email' => 'cliente@example.com', 'phone' => '11999991234',
            'service_id' => $this->service->id, 'barber_id' => $this->barber->id,
            'date' => '2026-11-09', 'time' => '09:00',
        ];
        foreach ([
            ['date' => '2026-11-08'],
            ['time' => '09:00'],
            ['time' => '09:40'],
        ] as $invalid) {
            $this->postJson('/schedule/appointments', [...$payload, ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->postJson('/schedule/appointments', [...$payload, 'time' => '10:20'])->assertCreated();
    }

    public function test_checkout_records_historical_values_and_locks_the_appointment(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'America/Sao_Paulo')->utc());
        $appointment = $this->appointment();
        $secondService = Service::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Barba', 'description' => 'Barba',
            'duration' => 20, 'price' => '12.35', 'is_active' => true,
        ]);
        $appointment->services()->sync([$this->service->id, $secondService->id]);
        $product = Product::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Pomada', 'brand' => 'Marca',
            'description' => 'Produto', 'price' => '10.05',
        ]);
        $this->actingAs($this->admin)->get('/schedule')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('barbershops.0.products.0.id', $product->id)->has('barbershops.1.products', 0));
        $payload = [
            'barbershop_id' => $this->shop->id, 'version' => $this->version($appointment),
            'services_total_cents' => 6235,
            'services' => [
                ['id' => $this->service->id, 'price_cents' => 5000],
                ['id' => $secondService->id, 'price_cents' => 1235],
            ],
            'products' => [['id' => $product->id, 'quantity' => 2, 'price_cents' => 1005]],
            'discount_cents' => 105, 'payment_method' => 'credit_card',
            'notes' => "Cliente solicitou embalagem.\nRetirada na próxima visita.",
        ];
        $this->actingAs($this->admin)->postJson('/schedule/appointments/'.$appointment->id.'/checkout', $payload)->assertOk();
        $this->assertDatabaseHas('appointment_checkouts', [
            'appointment_id' => $appointment->id, 'created_by' => $this->admin->id,
            'services_total_cents' => 6235, 'products_total_cents' => 2010,
            'discount_cents' => 105, 'total_cents' => 8140, 'payment_method' => 'credit_card',
            'notes' => $payload['notes'],
        ]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('completed', $appointment->fresh()->status);
        $this->postJson('/schedule/appointments/'.$appointment->id.'/checkout', $payload)->assertConflict();
        $this->update($appointment->fresh(), ['status' => 'cancelled'])->assertUnprocessable();
        $product->update(['name' => 'Nova pomada', 'price' => '90.00']);
        $product->delete();
        $secondService->update(['name' => 'Nova barba', 'price' => '100.00']);
        $this->events()->assertOk()->assertJsonPath('0.editable', false)
            ->assertJsonPath('0.extendedProps.status', 'completed')
            ->assertJsonPath('0.extendedProps.checkout.services.1.name', 'Barba')
            ->assertJsonPath('0.extendedProps.checkout.products.0.name', 'Pomada')
            ->assertJsonPath('0.extendedProps.checkout.notes', $payload['notes'])
            ->assertJsonPath('0.extendedProps.checkout.total_cents', 8140);
        $this->actingAs($this->barber);
        $this->get('/schedule')->assertOk()->assertInertia(fn (Assert $page) => $page->has('barbershops.0.products', 0));
        $this->events()->assertOk()->assertJsonPath('0.extendedProps.checkout', null)
            ->assertJsonPath('0.extendedProps.services.1.name', 'Barba')
            ->assertJsonPath('0.extendedProps.services.1.price', '12.35');
    }

    public function test_checkout_validates_authorization_prices_discounts_and_product_unit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'America/Sao_Paulo')->utc());
        $appointment = $this->appointment();
        $product = Product::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Pomada', 'brand' => 'Marca',
            'description' => 'Produto', 'price' => '10.00',
        ]);
        $otherProduct = Product::create([
            'barbershop_id' => $this->other->id, 'name' => 'Outro', 'brand' => 'Marca',
            'description' => 'Produto', 'price' => '10.00',
        ]);
        $payload = [
            'barbershop_id' => $this->shop->id, 'version' => $this->version($appointment),
            'services_total_cents' => 5000,
            'services' => [['id' => $this->service->id, 'price_cents' => 5000]],
            'products' => [['id' => $product->id, 'quantity' => 1, 'price_cents' => 1000]],
            'discount_cents' => 0, 'payment_method' => 'pix',
        ];
        $url = '/schedule/appointments/'.$appointment->id.'/checkout';
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs($this->barber)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($this->admin);
        foreach ([
            ['services_total_cents' => 4999],
            ['services' => [['id' => $this->service->id, 'price_cents' => 4999]]],
            ['products' => [['id' => $otherProduct->id, 'quantity' => 1, 'price_cents' => 1000]]],
            ['products' => [['id' => $product->id, 'quantity' => 0, 'price_cents' => 1000]]],
            ['products' => [['id' => $product->id, 'quantity' => 1, 'price_cents' => 999]]],
            ['products' => array_fill(0, 2, ['id' => $product->id, 'quantity' => 1, 'price_cents' => 1000])],
            ['discount_cents' => 6001], ['discount_cents' => -1],
            ['payment_method' => 'card'], ['payment_method' => null],
            ['notes' => ['inválido']], ['notes' => str_repeat('a', 5001)],
        ] as $invalid) {
            $this->postJson($url, [...$payload, ...$invalid])->assertUnprocessable();
        }
        $this->postJson($url, [...$payload, 'version' => str_repeat('0', 64)])->assertConflict();
        $this->postJson($url, [...$payload, 'barbershop_id' => $this->other->id])->assertNotFound();
        $this->assertDatabaseCount('appointment_checkouts', 0);
        $this->assertSame('scheduled', $appointment->fresh()->status);
        $this->postJson($url, [...$payload, 'products' => [], 'discount_cents' => 5000, 'payment_method' => 'cash'])->assertOk();
        $this->assertDatabaseHas('appointment_checkouts', ['appointment_id' => $appointment->id, 'total_cents' => 0, 'notes' => null]);
    }

    public function test_checkout_accepts_all_payment_methods_and_rejects_non_pending_appointments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'America/Sao_Paulo')->utc());
        $this->actingAs($this->admin);
        foreach (['credit_card', 'debit_card', 'pix', 'cash'] as $method) {
            $appointment = $this->appointment();
            $payload = [
                'barbershop_id' => $this->shop->id, 'version' => $this->version($appointment),
                'services_total_cents' => 5000,
                'services' => [['id' => $this->service->id, 'price_cents' => 5000]],
                'products' => [], 'discount_cents' => 0, 'payment_method' => $method,
            ];
            $url = '/schedule/appointments/'.$appointment->id.'/checkout';
            $this->postJson($url, $payload)->assertOk();
            $this->assertDatabaseHas('appointment_checkouts', [
                'appointment_id' => $appointment->id, 'payment_method' => $method, 'total_cents' => 5000,
            ]);
            $this->postJson($url, [...$payload, 'version' => $this->version($appointment->fresh())])->assertUnprocessable();
        }
        $cancelled = $this->appointment();
        $cancelled->update(['status' => 'cancelled']);
        $this->postJson('/schedule/appointments/'.$cancelled->id.'/checkout', [
            'barbershop_id' => $this->shop->id, 'version' => $this->version($cancelled),
            'services_total_cents' => 5000,
            'services' => [['id' => $this->service->id, 'price_cents' => 5000]],
            'products' => [], 'discount_cents' => 0, 'payment_method' => 'pix',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('appointment_checkouts', 4);
    }

    public function test_checkout_is_forbidden_until_the_exact_appointment_start_time(): void
    {
        $appointment = $this->appointment();
        $this->actingAs($this->admin);
        $payload = [
            'barbershop_id' => $this->shop->id, 'version' => $this->version($appointment),
            'services_total_cents' => 5000,
            'services' => [['id' => $this->service->id, 'price_cents' => 5000]],
            'products' => [], 'discount_cents' => 0, 'payment_method' => 'pix',
        ];
        $url = '/schedule/appointments/'.$appointment->id.'/checkout';

        foreach ([$appointment->start_time->copy()->subDay(), $appointment->start_time->copy()->subSecond()] as $now) {
            $this->travelTo($now);
            $this->events()->assertOk()->assertJsonPath('0.extendedProps.canCheckout', false);
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('start_time');
            $this->assertDatabaseCount('appointment_checkouts', 0);
            $this->assertSame('scheduled', $appointment->fresh()->status);
        }

        $this->travelTo($appointment->start_time);
        $this->events()->assertOk()->assertJsonPath('0.extendedProps.canCheckout', true);
        $this->actingAs($this->barber);
        $this->events()->assertOk()->assertJsonPath('0.extendedProps.canCheckout', false);
        $this->actingAs($this->admin)->postJson($url, $payload)->assertOk();
        $this->events()->assertOk()->assertJsonPath('0.extendedProps.canCheckout', false);
    }
}
