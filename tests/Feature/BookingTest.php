<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'America/Sao_Paulo')->utc());
    }

    private function shop(string $slug = 'centro', string $open = '09:00', string $close = '20:00'): Barbershop
    {
        return Barbershop::create([
            'name' => $slug, 'slug' => $slug, 'timezone' => 'America/Sao_Paulo',
            'is_open' => true, 'address' => 'Rua Central, 85',
            'opening_hours' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['open' => $open, 'close' => $close, 'closed' => false],
            ),
        ]);
    }

    private function service(Barbershop $shop, int $duration = 40): Service
    {
        return Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Corte', 'description' => 'Corte completo',
            'duration' => $duration, 'price' => 50, 'is_active' => true,
        ]);
    }

    private function barber(Barbershop $shop, Service $service): User
    {
        $barber = User::factory()->create([
            'role' => 'barber', 'barbershop_id' => $shop->id,
            'name' => 'Barbeiro '.(User::count() + 1),
        ]);
        $barber->barbershops()->attach($shop);
        $barber->services()->attach($service);

        return $barber;
    }

    private function selection(Barbershop $shop, Service $service, ?User $barber = null): array
    {
        return [
            'barbershop_id' => $shop->id, 'service_id' => $service->id,
            'barber_id' => $barber?->id, 'date' => '2026-11-09',
        ];
    }

    private function booking(Barbershop $shop, Service $service, User $barber, array $overrides = []): array
    {
        return array_merge($this->selection($shop, $service, $barber), [
            'name' => 'Cliente Teste', 'email' => 'cliente@example.com',
            'phone' => '(11) 99999-9999', 'time' => '09:00',
        ], $overrides);
    }

    private function availability(array $data)
    {
        return $this->getJson('/agendar/disponibilidade?'.http_build_query($data));
    }

    private function independentItems(Barbershop $shop, array $items): array
    {
        return [
            'name' => 'Cliente Teste', 'email' => 'cliente@example.com', 'phone' => '(11) 99999-9999',
            'barbershop_id' => $shop->id, 'items' => $items,
        ];
    }

    private function item(Service $service, User $barber, string $time = '09:00', string $date = '2026-11-09'): array
    {
        return ['service_id' => $service->id, 'barber_id' => $barber->id, 'date' => $date, 'time' => $time];
    }

    public function test_independent_services_need_no_common_barber_and_create_individual_calendar_events(): void
    {
        $shop = $this->shop();
        $first = $this->service($shop, 40);
        $second = $this->service($shop, 60);
        $barberA = $this->barber($shop, $first);
        $barberB = $this->barber($shop, $second);
        foreach ([$first, $second] as $service) {
            $this->availability($this->selection($shop, $service))->assertOk()->assertJsonCount(1, 'slots.0.barber_ids');
        }
        $response = $this->postJson('/agendar', $this->independentItems($shop, [
            $this->item($first, $barberA),
            $this->item($second, $barberB, '09:00', '2026-11-10'),
        ]))->assertCreated()->assertJsonCount(2, 'appointments')
            ->assertJsonPath('appointments.0.service.id', $first->id)
            ->assertJsonPath('appointments.1.barber.id', $barberB->id)
            ->assertJsonPath('appointments.0.starts_at', '2026-11-09T12:00:00+00:00')
            ->assertJsonPath('appointments.1.ends_at', '2026-11-10T13:00:00+00:00');
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('appointments', 2);
        $this->assertDatabaseCount('appointment_service', 2);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertSame(1, DB::table('appointments')->distinct()->count('client_id'));
        foreach ($response->json('appointments') as $booking) {
            $this->assertDatabaseHas('appointment_service', ['appointment_id' => $booking['id'], 'service_id' => $booking['service']['id']]);
            $notification = Notification::where('appointment_id', $booking['id'])->where('type', 'whatsapp')->firstOrFail();
            $this->assertSame('+5511999999999', $notification->payload['recipient']);
            $this->assertSame($booking['service']['duration'], $notification->payload['duration']);
            $this->assertSame('pending', $notification->status);
        }

        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $events = $this->actingAs($admin)->getJson('/schedule/events?'.http_build_query([
            'barbershop_id' => $shop->id,
            'start' => '2026-11-09T00:00:00-03:00', 'end' => '2026-11-11T00:00:00-03:00',
        ]))->assertOk()->json();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertCount(1, $event['extendedProps']['services']);
        }
    }

    public function test_independent_items_reject_customer_overlaps_even_with_different_barbers(): void
    {
        $shop = $this->shop();
        $first = $this->service($shop, 80);
        $second = $this->service($shop, 40);
        $barberA = $this->barber($shop, $first);
        $barberB = $this->barber($shop, $second);
        foreach (['09:00', '09:40'] as $time) {
            $this->postJson('/agendar', $this->independentItems($shop, [
                $this->item($first, $barberA), $this->item($second, $barberB, $time),
            ]))->assertUnprocessable()->assertJsonValidationErrors('items.1.time');
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->postJson('/agendar', $this->independentItems($shop, [
            $this->item($first, $barberA), $this->item($second, $barberB, '10:20'),
        ]))->assertCreated();
    }

    public function test_same_barber_can_receive_adjacent_independent_services_with_individual_durations(): void
    {
        $shop = $this->shop();
        $first = $this->service($shop, 40);
        $second = $this->service($shop, 60);
        $barber = $this->barber($shop, $first);
        $barber->services()->attach($second);
        $this->postJson('/agendar', $this->independentItems($shop, [
            $this->item($first, $barber), $this->item($second, $barber, '09:40'),
        ]))->assertCreated()
            ->assertJsonPath('appointments.0.ends_at', '2026-11-09T12:40:00+00:00')
            ->assertJsonPath('appointments.1.starts_at', '2026-11-09T12:40:00+00:00')
            ->assertJsonPath('appointments.1.ends_at', '2026-11-09T13:40:00+00:00');
    }

    public function test_losing_one_slot_rejects_the_entire_batch_without_creating_a_client_or_notifications(): void
    {
        $shop = $this->shop();
        $other = $this->shop('outra');
        $first = $this->service($shop);
        $second = $this->service($shop);
        $barberA = $this->barber($shop, $first);
        $barberB = $this->barber($shop, $second);
        $this->availability($this->selection($shop, $second, $barberB))->assertOk();
        $this->appointment($other, $second, $barberB, '2026-11-09 09:40', '2026-11-09 10:20');
        $this->postJson('/agendar', $this->independentItems($shop, [
            $this->item($first, $barberA), $this->item($second, $barberB, '09:40'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.1.time');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('appointment_service', 0);
    }

    public function test_independent_batch_validation_preserves_service_unit_and_date_constraints(): void
    {
        $shop = $this->shop();
        $first = $this->service($shop);
        $second = $this->service($shop);
        $barberA = $this->barber($shop, $first);
        $barberB = $this->barber($shop, $second);
        $items = [$this->item($first, $barberA), $this->item($second, $barberB, '09:40')];
        foreach ([
            ['items' => []],
            ['items' => [$items[0], $items[0]]],
            ['items' => [$items[0], [...$items[1], 'barber_id' => $barberA->id]]],
            ['items' => [$items[0], [...$items[1], 'date' => '2026-12-25']]],
            ['items' => [$items[0], [...$items[1], 'date' => '2026-11-08']]],
            ['service_ids' => [$first->id]],
            ['service_id' => $first->id],
            ['barber_id' => null],
        ] as $invalid) {
            $this->postJson('/agendar', [...$this->independentItems($shop, $items), ...$invalid])->assertUnprocessable();
        }
        $this->travel(2)->minutes();
        $barberB->services()->detach($second);
        $this->postJson('/agendar', $this->independentItems($shop, $items))->assertUnprocessable()->assertJsonValidationErrors('items.1.time');
        $barberB->services()->attach($second);
        $second->update(['is_active' => false]);
        $this->postJson('/agendar', $this->independentItems($shop, $items))->assertUnprocessable()->assertJsonValidationErrors('items.1.service_id');
        $second->update(['is_active' => true]);
        $barberB->barbershops()->detach($shop);
        $this->postJson('/agendar', $this->independentItems($shop, $items))->assertUnprocessable()->assertJsonValidationErrors('items.1.time');
        $barberB->barbershops()->attach($shop);
        $second->delete();
        $this->postJson('/agendar', $this->independentItems($shop, $items))->assertUnprocessable()->assertJsonValidationErrors('items.1.service_id');
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_independent_items_respect_global_blocks_and_the_units_timezone(): void
    {
        $shop = $this->shop();
        $shop->update(['timezone' => 'America/Los_Angeles']);
        $first = $this->service($shop);
        $second = $this->service($shop);
        $barberA = $this->barber($shop, $first);
        $barberB = $this->barber($shop, $second);
        $block = BlockedTime::create([
            'user_id' => $barberB->id,
            'start_datetime' => CarbonImmutable::parse('2026-11-09 09:40', $shop->timezone)->utc(),
            'end_datetime' => CarbonImmutable::parse('2026-11-09 10:20', $shop->timezone)->utc(),
        ]);
        $data = $this->independentItems($shop, [
            $this->item($first, $barberA), $this->item($second, $barberB, '09:40'),
        ]);
        $this->postJson('/agendar', $data)->assertUnprocessable()->assertJsonValidationErrors('items.1.time');
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('appointments', 0);
        $block->delete();
        $this->postJson('/agendar', $data)->assertCreated()
            ->assertJsonPath('appointments.0.starts_at', '2026-11-09T17:00:00+00:00')
            ->assertJsonPath('appointments.1.starts_at', '2026-11-09T17:40:00+00:00');
        $this->assertSame('2026-11-09T09:00:00-08:00', Notification::firstOrFail()->payload['starts_at']);
    }

    public function test_notification_failure_rolls_back_every_created_item(): void
    {
        $shop = $this->shop();
        $first = $this->service($shop);
        $second = $this->service($shop);
        $barberA = $this->barber($shop, $first);
        $barberB = $this->barber($shop, $second);
        $this->withoutExceptionHandling();
        $originalDispatcher = Notification::getEventDispatcher();
        Notification::setEventDispatcher(clone $originalDispatcher);
        Notification::creating(function ($notification) {
            if ($notification->type === 'email' && Notification::count() === 2) {
                throw new \RuntimeException('Synthetic notification failure');
            }
        });
        try {
            $this->postJson('/agendar', $this->independentItems($shop, [
                $this->item($first, $barberA), $this->item($second, $barberB, '09:40'),
            ]));
            $this->fail('The notification failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic notification failure', $exception->getMessage());
        } finally {
            Notification::setEventDispatcher($originalDispatcher);
        }
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_service', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    private function appointment(Barbershop $shop, Service $service, User $barber, string $start, string $end, string $status = 'scheduled'): Appointment
    {
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Outro cliente',
            'email' => 'outro@example.com', 'phone' => '11999999999',
        ]);

        return Appointment::create([
            'barbershop_id' => $shop->id, 'client_id' => $client->id, 'user_id' => $barber->id,
            'service_id' => $service->id, 'start_time' => CarbonImmutable::parse($start, $shop->timezone)->utc(),
            'end_time' => CarbonImmutable::parse($end, $shop->timezone)->utc(), 'status' => $status,
        ]);
    }

    public function test_catalog_uses_barber_unit_and_service_associations_even_for_authenticated_visitors(): void
    {
        $shop = $this->shop();
        $other = $this->shop('outra');
        $available = $this->service($other);
        $barber = $this->barber($shop, $available);
        $this->service($shop);
        $inactive = $this->service($shop);
        $inactive->update(['is_active' => false]);
        $barber->services()->attach($inactive);
        $this->barber($other, $this->service($other));
        $this->actingAs(User::factory()->create(['role' => 'admin', 'barbershop_id' => $other->id]));

        $this->get('/agendar')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Booking')->has('barbershops', 2)
            ->has('barbershops.0.services', 1)->where('barbershops.0.services.0.id', $available->id)
            ->has('barbershops.0.barbers', 1)->where('barbershops.0.barbers.0.id', $barber->id)
            ->missing('barbershops.0.barbers.0.email')->where('barbershops.0.today', '2026-11-09'));
    }

    public function test_slots_follow_the_forty_minute_grid_and_respect_service_duration(): void
    {
        $shop = $this->shop(close: '19:40');
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $response = $this->availability($this->selection($shop, $service, $barber))->assertOk();
        $this->assertSame([
            '09:00', '09:40', '10:20', '11:00', '11:40', '12:20', '13:00', '13:40',
            '14:20', '15:00', '15:40', '16:20', '17:00', '17:40', '18:20', '19:00',
        ], array_column($response->json('slots'), 'time'));
        $service->update(['duration' => 80]);
        $response = $this->availability($this->selection($shop, $service))->assertOk();
        $this->assertNotContains('19:00', array_column($response->json('slots'), 'time'));
        $this->assertContains('18:20', array_column($response->json('slots'), 'time'));
    }

    public function test_union_excludes_busy_barbers_and_checks_overlapping_bookings_in_other_units(): void
    {
        $shop = $this->shop(close: '12:00');
        $other = $this->shop('outra');
        $service = $this->service($shop, 60);
        $first = $this->barber($shop, $service);
        $second = $this->barber($shop, $service);
        $this->appointment($other, $service, $first, '2026-11-09 09:30', '2026-11-09 10:30');
        BlockedTime::create([
            'user_id' => $second->id,
            'start_datetime' => CarbonImmutable::parse('2026-11-09 10:20', $shop->timezone)->utc(),
            'end_datetime' => CarbonImmutable::parse('2026-11-09 11:00', $shop->timezone)->utc(),
        ]);
        $response = $this->availability($this->selection($shop, $service))->assertOk();
        $this->assertSame([
            ['time' => '09:00', 'barber_ids' => [$second->id]],
            ['time' => '11:00', 'barber_ids' => [$first->id, $second->id]],
        ], $response->json('slots'));
    }

    public function test_working_hours_past_times_closed_days_and_date_window_are_respected(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        WorkingHour::create(['user_id' => $barber->id, 'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '11:40']);
        $response = $this->availability($this->selection($shop, $service))->assertOk();
        $this->assertSame(['10:20', '11:00'], array_column($response->json('slots'), 'time'));
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:20', $shop->timezone)->utc());
        $this->assertSame(['11:00'], array_column($this->availability($this->selection($shop, $service))->json('slots'), 'time'));

        foreach (['2026-11-08', '2026-12-25'] as $date) {
            $this->availability([...$this->selection($shop, $service), 'date' => $date])
                ->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        $this->availability([...$this->selection($shop, $service), 'date' => '2026-12-24'])->assertOk();
        $this->availability([...$this->selection($shop, $service), 'date' => '2026-02-30'])->assertUnprocessable();
        $hours = $shop->opening_hours;
        $hours['monday']['closed'] = true;
        $shop->update(['opening_hours' => $hours]);
        $this->availability($this->selection($shop, $service))->assertOk()->assertJsonPath('slots', []);
    }

    public function test_direct_confirmation_rejects_past_dates_elapsed_slots_and_the_current_instant(): void
    {
        $shop = $this->shop();
        $shop->update(['timezone' => 'America/Los_Angeles']);
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:40', $shop->timezone)->utc());

        foreach ([
            ['date' => '2026-11-08', 'time' => '09:00'],
            ['time' => '09:00'],
            ['time' => '09:40'],
        ] as $invalid) {
            $this->postJson('/agendar', $this->booking($shop, $service, $barber, $invalid))
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->postJson('/agendar', $this->booking($shop, $service, $barber, ['time' => '10:20']))
            ->assertCreated();
    }

    public function test_confirmation_persists_utc_times_and_two_pending_notification_payloads_atomically(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $this->postJson('/agendar', $this->booking($shop, $service, $barber))->assertCreated()->assertJsonStructure(['id']);
        $appointment = Appointment::withoutGlobalScope(BarbershopScope::class)->firstOrFail();
        $this->assertSame('2026-11-09 12:00:00', $appointment->start_time->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 12:40:00', $appointment->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $appointment->status);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', ['id' => $appointment->client_id, 'phone' => '+5511999999999']);
        $this->assertDatabaseCount('notifications', 2);
        foreach (Notification::all() as $notification) {
            $this->assertSame($appointment->id, $notification->appointment_id);
            $this->assertSame('pending', $notification->status);
            $this->assertNull($notification->sent_at);
            $this->assertSame('2026-11-09T09:00:00-03:00', $notification->payload['starts_at']);
            $this->assertSame($notification->type === 'email' ? 'cliente@example.com' : '+5511999999999', $notification->payload['recipient']);
        }

        $this->postJson('/agendar', $this->booking($shop, $service, $barber))
            ->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_international_phone_is_saved_in_the_client_and_notification_recipient(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $this->postJson('/agendar', $this->booking($shop, $service, $barber, [
            'phone' => '+290 21234',
        ]))->assertCreated();
        $this->assertDatabaseHas('clients', ['phone' => '+29021234']);
        $this->assertSame('+29021234', Notification::where('type', 'whatsapp')->firstOrFail()->payload['recipient']);
    }

    public function test_invalid_international_phone_cannot_create_a_client_or_notification(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        foreach (['+999123456789', '+1202555012345678', '+5511999999999 ext 1'] as $phone) {
            $this->postJson('/agendar', $this->booking($shop, $service, $barber, [
                'phone' => $phone,
            ]))->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_booking_combines_services_and_uses_their_total_duration_and_price(): void
    {
        $shop = $this->shop(close: '19:40');
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $secondService = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Barba', 'description' => 'Barba completa',
            'duration' => 60, 'price' => 35, 'is_active' => true,
        ]);
        $barber->services()->attach($secondService);
        $barberWithoutSecondService = $this->barber($shop, $service);
        $selection = [
            'barbershop_id' => $shop->id,
            'service_ids' => [$service->id, $secondService->id],
            'barber_id' => $barber->id,
            'date' => '2026-11-09',
        ];

        $slots = $this->availability($selection)->assertOk()->json('slots');
        $this->assertContains('09:00', array_column($slots, 'time'));
        $this->assertNotContains('19:00', array_column($slots, 'time'));
        $this->assertSame([], $this->availability([
            ...$selection, 'barber_id' => $barberWithoutSecondService->id,
        ])->assertOk()->json('slots'));
        $this->postJson('/agendar', $this->booking($shop, $service, $barber, [
            'service_ids' => [$service->id, $secondService->id],
        ]))->assertCreated();

        $appointment = Appointment::withoutGlobalScope(BarbershopScope::class)->firstOrFail();
        $this->assertSame($service->id, $appointment->service_id);
        $this->assertDatabaseHas('appointment_service', [
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
        ]);
        $this->assertDatabaseHas('appointment_service', [
            'appointment_id' => $appointment->id, 'service_id' => $secondService->id,
        ]);
        $this->assertSame('2026-11-09 13:40:00', $appointment->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('Corte + Barba', Notification::firstOrFail()->payload['service']);
        $this->assertSame(100, Notification::firstOrFail()->payload['duration']);
        $this->assertEquals(85, Notification::firstOrFail()->payload['price']);
    }

    public function test_confirmation_rejects_invalid_relationships_and_forged_or_stale_slots(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $other = $this->shop('outra');
        $unrelated = $this->barber($other, $this->service($other));

        foreach ([
            ['barber_id' => $unrelated->id], ['barbershop_id' => $other->id],
            ['time' => '09:10'], ['date' => '2026-12-25'],
            ['email' => 'invalid'], ['phone' => 'invalid'], ['phone' => '(())------'], ['barber_id' => null],
        ] as $overrides) {
            $this->postJson('/agendar', $this->booking($shop, $service, $barber, $overrides))->assertUnprocessable();
        }
        $this->availability($this->selection($shop, $service))->assertOk();
        $barber->services()->detach($service);
        $this->postJson('/agendar', $this->booking($shop, $service, $barber))->assertUnprocessable();
        $barber->services()->attach($service);
        $service->delete();
        $this->postJson('/agendar', $this->booking($shop, $service, $barber))->assertUnprocessable();
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_cancelled_and_deleted_appointments_do_not_block_but_adjacent_bookings_are_allowed(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $this->appointment($shop, $service, $barber, '2026-11-09 09:00', '2026-11-09 09:40', 'cancelled');
        $deleted = $this->appointment($shop, $service, $barber, '2026-11-09 09:00', '2026-11-09 09:40');
        $deleted->delete();
        $this->appointment($shop, $service, $barber, '2026-11-09 09:40', '2026-11-09 10:20');
        $times = array_column($this->availability($this->selection($shop, $service))->assertOk()->json('slots'), 'time');
        $this->assertContains('09:00', $times);
        $this->assertNotContains('09:40', $times);
        $this->assertContains('10:20', $times);
    }

    public function test_slots_are_anchored_to_opening_and_confirmation_uses_the_units_timezone(): void
    {
        $shop = $this->shop(open: '09:30', close: '11:20');
        $shop->update(['timezone' => 'America/Los_Angeles']);
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        $response = $this->availability($this->selection($shop, $service))->assertOk();
        $this->assertSame(['09:30', '10:10'], array_column($response->json('slots'), 'time'));
        $this->postJson('/agendar', $this->booking($shop, $service, $barber, ['time' => '09:30']))->assertCreated();
        $this->assertDatabaseHas('appointments', ['start_time' => '2026-11-09 17:30:00', 'end_time' => '2026-11-09 18:10:00']);
        $this->assertSame('2026-11-09T09:30:00-08:00', Notification::firstOrFail()->payload['starts_at']);
    }

    public function test_availability_queries_do_not_consume_the_confirmation_rate_limit(): void
    {
        $shop = $this->shop();
        $service = $this->service($shop);
        $barber = $this->barber($shop, $service);
        for ($i = 0; $i < 11; $i++) {
            $this->availability($this->selection($shop, $service))->assertOk();
        }
        $this->postJson('/agendar', $this->booking($shop, $service, $barber))->assertCreated();
    }
}
