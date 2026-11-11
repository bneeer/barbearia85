<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\DashboardAppointments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    private function completedAppointment(Barbershop $shop, User $barber, bool $checkout = true): Appointment
    {
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Cliente teste',
            'email' => 'cliente@example.com', 'phone' => '11999998888',
        ]);
        $service = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Corte', 'description' => 'Corte',
            'duration' => 40, 'price' => '50.00', 'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id, 'user_id' => $barber->id, 'client_id' => $client->id,
            'service_id' => $service->id, 'start_time' => '2026-11-10 02:30:00',
            'end_time' => '2026-11-10 03:10:00', 'status' => 'completed',
            'notes' => 'Reserva pelo telefone',
        ]);
        $appointment->services()->attach($service);
        if ($checkout) {
            $appointment->checkout()->create([
                'created_by' => $barber->id,
                'services' => [['id' => $service->id, 'name' => 'Corte histórico', 'duration' => 40, 'price_cents' => 5000]],
                'products' => [['id' => 1, 'name' => 'Óleo para barba', 'brand' => 'Marca teste', 'quantity' => 2, 'price_cents' => 1000]],
                'services_total_cents' => 5000, 'products_total_cents' => 2000, 'discount_cents' => 500,
                'total_cents' => 6500, 'payment_method' => 'pix', 'notes' => 'Desconto fidelidade',
            ]);
        }

        return $appointment;
    }

    private function shop(string $name = 'Centro'): Barbershop
    {
        return Barbershop::create(['name' => $name, 'slug' => strtolower($name), 'timezone' => 'America/Sao_Paulo']);
    }

    public function test_service_highlights_use_completed_snapshots_and_global_range_filters(): void
    {
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin']);
        $barber = User::factory()->create(['role' => 'barber']);
        $barber->barbershops()->attach($shop->id);
        $appointment = $this->completedAppointment($shop, $barber);
        $service = $appointment->service;
        $service->update(['name' => 'Nome atual', 'price' => '999.00']);
        $repeat = $appointment->replicate();
        $repeat->save();
        $repeat->checkout()->create($appointment->checkout->only([
            'created_by', 'services', 'products', 'services_total_cents',
            'products_total_cents', 'discount_cents', 'total_cents', 'payment_method',
        ]));
        $repeat->services()->attach($service);
        $legacy = $this->completedAppointment($shop, $barber, false);
        $legacy->services()->detach();
        $this->completedAppointment($other, $barber);
        foreach (['scheduled', 'cancelled', 'no_show'] as $status) {
            $this->completedAppointment($shop, $barber)->update(['status' => $status]);
        }
        $this->completedAppointment($shop, $barber)->delete();
        $this->completedAppointment($shop, $barber)->update([
            'start_time' => '2026-11-10 03:00:00',
        ]);
        $query = http_build_query([
            'range_from' => '2026-11-09', 'range_to' => '2026-11-09',
            'barbershop_id' => $shop->id, 'services' => 'Inexistente', 'per_page' => 10,
        ]);

        $this->actingAs($admin)->get('/dashboard?'.$query)->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 0)
            ->where('rangeTimeline.series', function ($series) use ($service, $legacy) {
                $completed = collect($series)->filter(fn ($item) => array_sum($item['counts']) > 0)->keyBy('id');

                return $completed->count() === 2
                    && $completed[$service->id]['name'] === 'Corte histórico'
                    && array_sum($completed[$service->id]['counts']) === 2
                    && $completed[$service->id]['revenue_cents'] === 10000
                    && $completed[$legacy->service_id]['revenue_cents'] === null;
            }));

        $service->delete();
        $this->actingAs($barber)->get('/dashboard?'.$query)->assertInertia(fn (Assert $page) => $page
            ->where('rangeTimeline.series', fn ($series) => collect($series)->every(
                fn ($item) => ! array_key_exists('revenue_cents', $item)
            )));
        $this->actingAs(User::factory()->create(['role' => 'barber']))
            ->get('/dashboard?range_from=2026-11-09&range_to=2026-11-09')
            ->assertInertia(fn (Assert $page) => $page->where('rangeTimeline.series', []));
        $this->actingAs(User::factory()->create(['role' => 'client']))
            ->get('/dashboard')->assertInertia(fn (Assert $page) => $page->missing('rangeTimeline'));
    }

    public function test_service_highlights_do_not_treat_incomplete_revenue_as_zero(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->completedAppointment($shop, $admin);
        $legacy = $appointment->replicate();
        $legacy->save();
        $legacy->services()->attach($appointment->service_id);

        $this->actingAs($admin)->get('/dashboard?range_from=2026-11-09&range_to=2026-11-09')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rangeTimeline.series', 1)
                ->where('rangeTimeline.series.0.counts', [2])
                ->where('rangeTimeline.series.0.revenue_cents', null));
    }

    private function indicatorsWithoutCheckouts(array $periods): array
    {
        return array_map(fn (array $indicator) => [
            ...$indicator,
            'financials' => [
                'completed_cents' => 0,
                'scheduled_cents' => $indicator['scheduled'] * 5000,
                'total_cents' => $indicator['scheduled'] * 5000,
                'missing_completed' => $indicator['completed'],
                'missing_scheduled' => 0,
            ],
        ], $periods);
    }

    public function test_only_admins_receive_completed_appointments_including_other_units(): void
    {
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $barber = User::factory()->create(['role' => 'barber']);
        $first = $this->completedAppointment($shop, $barber);
        $second = $this->completedAppointment($other, $barber);
        foreach (['scheduled', 'cancelled', 'no_show'] as $status) {
            $this->completedAppointment($shop, $barber)->update(['status' => $status]);
        }
        $this->completedAppointment($shop, $barber)->delete();

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('appointments.total', 2)
            ->where('appointments.data.0.id', $second->id)
            ->where('appointments.data.0.unit', 'Norte')
            ->where('appointments.data.0.client.email', 'cliente@example.com')
            ->where('appointments.data.1.id', $first->id));
        foreach (['client'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/dashboard?total_min=0')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')->missing('appointments')->missing('filters')->missing('indicators')->missing('servicesTimeline'));
            $this->get('/dashboard', [
                'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'appointments',
                'X-Inertia-Version' => Inertia::getVersion(),
            ])->assertOk()->assertJsonMissingPath('props.appointments');
        }
    }

    public function test_table_preserves_checkout_snapshots_and_soft_deleted_relations(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->completedAppointment($shop, $admin);
        $appointment->service->update(['name' => 'Novo serviço', 'price' => '900.00']);
        $appointment->service->delete();
        $appointment->client->delete();
        Product::create([
            'barbershop_id' => $shop->id, 'name' => 'Produto alterado', 'brand' => 'Outra marca',
            'description' => 'Produto', 'price' => '999.00',
        ]);

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.data.0.services.0.name', 'Corte histórico')
            ->where('appointments.data.0.services.0.price_cents', 5000)
            ->where('appointments.data.0.checkout.products.0.name', 'Óleo para barba')
            ->where('appointments.data.0.checkout.products.0.quantity', 2)
            ->where('appointments.data.0.checkout.total_cents', 6500)
            ->where('appointments.data.0.checkout.notes', 'Desconto fidelidade')
            ->where('appointments.data.0.notes', 'Reserva pelo telefone')
            ->where('appointments.data.0.client.name', 'Cliente teste'));
    }

    public function test_individual_column_filters_and_combined_filters_use_all_records(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Barbeiro teste']);
        $appointment = $this->completedAppointment($shop, $admin);
        $matching = [
            'id' => $appointment->id, 'unit' => 'Centro', 'barber' => 'Barbeiro teste',
            'client' => 'Cliente', 'email' => 'cliente@', 'phone' => '98888',
            'services' => 'histórico', 'products' => 'Óleo', 'payment_method' => 'pix',
            'notes' => 'fidelidade', 'date_from' => '2026-11-09', 'date_to' => '2026-11-09',
            'services_total_min' => 5000, 'services_total_max' => 5000,
            'products_total_min' => 2000, 'products_total_max' => 2000,
            'discount_min' => 500, 'discount_max' => 500, 'total_min' => 6500, 'total_max' => 6500,
        ];
        $this->actingAs($admin);
        foreach ($matching as $field => $value) {
            $this->get('/dashboard?'.http_build_query([$field => $value]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 1));
        }
        $this->get('/dashboard?'.http_build_query($matching))->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 1)->where('appointments.data.0.id', $appointment->id));

        $nonMatching = [
            'id' => 99999, 'unit' => 'Norte', 'barber' => 'Ausente', 'client' => 'Ausente',
            'email' => 'ausente@', 'phone' => '00000', 'services' => 'Ausente', 'products' => 'Ausente',
            'payment_method' => 'cash', 'notes' => 'Ausente',
            'date_from' => '2026-11-10', 'date_to' => '2026-11-08',
            'services_total_min' => 5001, 'services_total_max' => 4999,
            'products_total_min' => 2001, 'products_total_max' => 1999,
            'discount_min' => 501, 'discount_max' => 499, 'total_min' => 6501, 'total_max' => 6499,
        ];
        foreach ($nonMatching as $field => $value) {
            $this->get('/dashboard?'.http_build_query([$field => $value]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));
        }
    }

    public function test_pagination_keeps_filters_and_orders_ties_by_id(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 12; $i++) {
            $last = $this->completedAppointment($shop, $admin);
        }
        $this->actingAs($admin)->get('/dashboard?unit=Centro&per_page=10')->assertInertia(fn (Assert $page) => $page
            ->has('appointments.data', 10)->where('appointments.total', 12)
            ->where('appointments.data.0.id', $last->id)
            ->where('appointments.next_page_url', fn ($url) => str_contains($url, 'unit=Centro')
                && str_contains($url, 'per_page=10') && str_contains($url, 'page=2')));
        $this->get('/dashboard?unit=Centro&per_page=10&page=2')->assertInertia(fn (Assert $page) => $page
            ->has('appointments.data', 2)->where('appointments.from', 11)->where('appointments.to', 12));
    }

    public function test_legacy_completed_appointments_do_not_invent_financial_values(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->completedAppointment($shop, $admin, checkout: false);
        $this->actingAs($admin)->get('/dashboard?services=Corte')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 1)->where('appointments.data.0.checkout', null)
            ->where('appointments.data.0.services.0.name', 'Corte')->missing('appointments.data.0.services.0.price_cents'));
        $this->get('/dashboard?total_min=0')->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));
    }

    public function test_filters_validate_ranges_and_page_sizes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach ([
            ['per_page' => 1000], ['page' => 0], ['id' => -1], ['payment_method' => 'invalid'],
            ['date_from' => 'invalid'],
            ['total_min' => -1], ['total_min' => 200, 'total_max' => 100], ['unit' => str_repeat('a', 101)],
        ] as $query) {
            $this->getJson('/dashboard?'.http_build_query($query))->assertUnprocessable();
        }
        $this->get('/dashboard?'.http_build_query([
            'date_from' => '2026-11-10', 'date_to' => '2026-11-09',
        ]))->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));
    }

    public function test_indicators_and_drilldowns_share_period_boundaries_and_statuses_across_units(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $tokyo = $this->shop('Tokyo');
        $tokyo->update(['timezone' => 'Asia/Tokyo']);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $fixtures = [
            [$shop, 'completed', '2026-11-10 02:30:00'],
            [$shop, 'scheduled', '2026-11-09 12:00:00'],
            [$shop, 'completed', '2026-11-16 02:59:59'],
            [$shop, 'scheduled', '2026-11-16 03:00:00'],
            [$shop, 'completed', '2026-11-01 03:00:00'],
            [$shop, 'scheduled', '2026-12-01 02:59:59'],
            [$shop, 'completed', '2026-12-01 03:00:00'],
            [$shop, 'completed', '2026-11-01 02:59:59'],
            [$tokyo, 'completed', '2026-11-08 15:00:00'],
            [$tokyo, 'scheduled', '2026-11-09 14:59:59'],
            [$tokyo, 'scheduled', '2026-11-09 15:00:00'],
            [$tokyo, 'completed', '2026-11-15 14:59:59'],
            [$tokyo, 'completed', '2026-11-15 15:00:00'],
        ];
        foreach ($fixtures as [$unit, $status, $start]) {
            $appointment = $this->completedAppointment($unit, $admin, checkout: false);
            $appointment->update([
                'status' => $status, 'start_time' => $start,
                'end_time' => CarbonImmutable::parse($start)->addMinutes(40),
            ]);
        }
        foreach (['cancelled', 'no_show'] as $status) {
            $this->completedAppointment($shop, $admin, checkout: false)->update(['status' => $status]);
        }
        $this->completedAppointment($shop, $admin, checkout: false)->delete();
        $expected = $this->indicatorsWithoutCheckouts([
            ['period' => 'day', 'completed' => 1, 'scheduled' => 3, 'total' => 4],
            ['period' => 'week', 'completed' => 4, 'scheduled' => 3, 'total' => 7],
            ['period' => 'month', 'completed' => 6, 'scheduled' => 5, 'total' => 11],
        ]);
        $this->actingAs($admin)->get('/dashboard?unit=Inexistente&total_min=99999')
            ->assertInertia(fn (Assert $page) => $page->where('indicators.periods', $expected)
                ->where('indicators.barbers.0.periods', $expected)
                ->where('appointments.total', 0));
        foreach ($expected as $indicator) {
            $query = http_build_query(['period' => $indicator['period'], 'period_at' => now()->toIso8601String()]);
            $this->get('/dashboard?'.$query)->assertInertia(fn (Assert $page) => $page
                ->where('appointments.total', $indicator['completed'])
                ->where('filters.period', $indicator['period']));
            $this->getJson('/dashboard/scheduled?'.$query)->assertOk()
                ->assertJsonPath('total', $indicator['scheduled']);
        }
        $this->get('/dashboard?'.http_build_query([
            'period' => 'day', 'period_at' => now()->toIso8601String(), 'unit' => 'Tokyo',
        ]))->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));
    }

    public function test_period_reference_survives_midnight_and_drilldown_pagination(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10T02:59:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 12; $i++) {
            $last = $this->completedAppointment($shop, $admin, checkout: false);
        }
        $query = ['period' => 'day', 'period_at' => now()->toIso8601String(), 'per_page' => 10];
        $this->actingAs($admin)->get('/dashboard?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
            ->where('indicators.periods.0.completed', 12)->where('appointments.total', 12)
            ->where('appointments.next_page_url', fn ($url) => str_contains($url, 'period=day')
                && str_contains($url, 'period_at=') && str_contains($url, 'page=2')));
        $this->travelTo(CarbonImmutable::parse('2026-11-10T03:01:00+00:00'));
        $this->get('/dashboard?'.http_build_query([...$query, 'page' => 2]))->assertInertia(fn (Assert $page) => $page
            ->where('indicators.periods.0.completed', 0)->where('appointments.total', 12)->has('appointments.data', 2));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 12)->where('appointments.data.0.id', $last->id));
    }

    public function test_scheduled_popup_is_paginated_and_includes_read_only_consultation_details(): void
    {
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Barbeiro']);
        for ($i = 0; $i < 12; $i++) {
            $appointment = $this->completedAppointment($shop, $admin, checkout: false);
            $appointment->update(['status' => 'scheduled']);
        }
        $query = ['period' => 'day', 'period_at' => '2026-11-09T12:00:00+00:00'];
        $this->actingAs($admin)->getJson('/dashboard/scheduled?'.http_build_query($query))
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('total', 12)
            ->assertJsonPath('data.0.unit', 'Centro')->assertJsonPath('data.0.barber', 'Barbeiro')
            ->assertJsonPath('data.0.client.name', 'Cliente teste')->assertJsonPath('data.0.client.phone', '11999998888')
            ->assertJsonPath('data.0.services.0', 'Corte')->assertJsonPath('data.0.timezone', 'America/Sao_Paulo')
            ->assertJsonMissingPath('data.0.checkout');
        $this->getJson('/dashboard/scheduled?'.http_build_query([...$query, 'page' => 2]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.id', $appointment->id);
    }

    public function test_indicator_drilldowns_are_validated_and_protected(): void
    {
        $url = '/dashboard/scheduled?'.http_build_query(['period' => 'day', 'period_at' => '2026-11-09T12:00:00+00:00']);
        $this->getJson($url)->assertUnauthorized();
        foreach (['client'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['/dashboard', '/dashboard/scheduled'] as $path) {
            foreach ([
                ['period' => 'year', 'period_at' => '2026-11-09T12:00:00+00:00'],
                ['period' => 'day'], ['period' => 'day', 'period_at' => 'invalid'],
            ] as $query) {
                $this->getJson($path.'?'.http_build_query($query))->assertUnprocessable();
            }
        }
        $this->getJson('/dashboard/scheduled')->assertUnprocessable();
        $this->getJson($url.'&page=0')->assertUnprocessable();
    }

    public function test_empty_indicators_and_scheduled_popup_return_zero_results(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('servicesTimeline.dates', 60)->has('servicesTimeline.series', 0)
                ->has('indicators.barbers', 0)->where('indicators.periods', $this->indicatorsWithoutCheckouts([
                    ['period' => 'day', 'completed' => 0, 'scheduled' => 0, 'total' => 0],
                    ['period' => 'week', 'completed' => 0, 'scheduled' => 0, 'total' => 0],
                    ['period' => 'month', 'completed' => 0, 'scheduled' => 0, 'total' => 0],
                ])));
        $this->getJson('/dashboard/scheduled?'.http_build_query([
            'period' => 'day', 'period_at' => now()->toIso8601String(),
        ]))->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_barber_cards_include_photos_zero_counts_and_aggregate_without_duplicating_units(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $other->id]);
        $barber = User::factory()->create(['role' => 'barber', 'name' => 'Ana', 'barbershop_id' => $shop->id]);
        $barber->forceFill(['photo_path' => 'barbers/ana.jpg'])->save();
        $barber->barbershops()->attach([$shop->id, $other->id]);
        $idle = User::factory()->create(['role' => 'barber', 'name' => 'Bruno']);
        $colleague = User::factory()->create(['role' => 'barber', 'name' => 'Carlos']);
        $this->completedAppointment($shop, $barber, checkout: false);
        $this->completedAppointment($other, $barber, checkout: false)->update(['status' => 'scheduled']);
        $this->completedAppointment($shop, $barber, checkout: false)->update(['start_time' => '2026-11-11 12:00:00']);
        $this->completedAppointment($shop, $barber, checkout: false)->update([
            'status' => 'scheduled', 'start_time' => '2026-11-20 12:00:00',
        ]);
        $this->completedAppointment($shop, $colleague, checkout: false);
        foreach (['cancelled', 'no_show'] as $status) {
            $this->completedAppointment($shop, $barber, checkout: false)->update(['status' => $status]);
        }
        $this->completedAppointment($shop, $barber, checkout: false)->delete();
        $this->completedAppointment($shop, $barber, checkout: false)->update(['start_time' => '2026-10-30 12:00:00']);
        $this->actingAs($admin)->get('/dashboard?unit=Inexistente')
            ->assertInertia(fn (Assert $page) => $page->has('indicators.barbers', 3)
                ->where('indicators.barbers.0.id', $barber->id)
                ->where('indicators.barbers.0.name', 'Ana')
                ->where('indicators.barbers.0.avatar', $barber->avatar)
                ->where('indicators.barbers.0.periods', $this->indicatorsWithoutCheckouts([
                    ['period' => 'day', 'completed' => 1, 'scheduled' => 1, 'total' => 2],
                    ['period' => 'week', 'completed' => 2, 'scheduled' => 1, 'total' => 3],
                    ['period' => 'month', 'completed' => 2, 'scheduled' => 2, 'total' => 4],
                ]))
                ->where('indicators.barbers.1.id', $idle->id)->where('indicators.barbers.1.avatar', null)
                ->where('indicators.barbers.1.periods', $this->indicatorsWithoutCheckouts([
                    ['period' => 'day', 'completed' => 0, 'scheduled' => 0, 'total' => 0],
                    ['period' => 'week', 'completed' => 0, 'scheduled' => 0, 'total' => 0],
                    ['period' => 'month', 'completed' => 0, 'scheduled' => 0, 'total' => 0],
                ]))
                ->where('indicators.barbers.2.id', $colleague->id)
                ->where('indicators.barbers.2.periods.0.total', 1)
                ->where('indicators.periods', $this->indicatorsWithoutCheckouts([
                    ['period' => 'day', 'completed' => 2, 'scheduled' => 1, 'total' => 3],
                    ['period' => 'week', 'completed' => 3, 'scheduled' => 1, 'total' => 4],
                    ['period' => 'month', 'completed' => 3, 'scheduled' => 2, 'total' => 5],
                ]))->where('appointments.total', 0));
    }

    public function test_barber_drilldowns_match_card_counts_by_id_for_every_period_and_keep_pagination(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $other->id]);
        $barber = User::factory()->create(['role' => 'barber', 'name' => 'Mesmo nome']);
        $colleague = User::factory()->create(['role' => 'barber', 'name' => 'Mesmo nome']);
        for ($i = 0; $i < 12; $i++) {
            foreach (['completed', 'scheduled'] as $status) {
                $this->completedAppointment($shop, $barber, checkout: false)->update(['status' => $status]);
            }
        }
        foreach (['completed', 'scheduled'] as $status) {
            $this->completedAppointment($other, $barber, checkout: false)->update([
                'status' => $status, 'start_time' => '2026-11-11 12:00:00',
            ]);
            $this->completedAppointment($other, $barber, checkout: false)->update([
                'status' => $status, 'start_time' => '2026-11-20 12:00:00',
            ]);
            $this->completedAppointment($shop, $colleague, checkout: false)->update(['status' => $status]);
        }
        $this->actingAs($admin);
        foreach (['day' => 12, 'week' => 13, 'month' => 14] as $period => $count) {
            $query = [
                'period' => $period, 'period_at' => now()->toIso8601String(),
                'barber_id' => $barber->id, 'per_page' => 10,
            ];
            $this->get('/dashboard?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
                ->where('appointments.total', $count)->has('appointments.data', 10)
                ->where('filters.barber_id', (string) $barber->id)
                ->where('appointments.next_page_url', fn ($url) => str_contains($url, 'barber_id='.$barber->id)
                    && str_contains($url, 'period='.$period) && str_contains($url, 'page=2')));
            $this->getJson('/dashboard/scheduled?'.http_build_query($query))->assertOk()
                ->assertJsonPath('total', $count)->assertJsonCount(10, 'data')
                ->assertJsonPath('next_page_url', fn ($url) => str_contains($url, 'barber_id='.$barber->id));
            $this->get('/dashboard?'.http_build_query([...$query, 'page' => 2]))->assertInertia(fn (Assert $page) => $page
                ->has('appointments.data', $count - 10)->where('appointments.total', $count));
            $this->getJson('/dashboard/scheduled?'.http_build_query([...$query, 'page' => 2]))
                ->assertOk()->assertJsonCount($count - 10, 'data')->assertJsonPath('total', $count);
        }
        $this->get('/dashboard?barber_id='.$colleague->id)->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 1));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 13));
        foreach (['/dashboard', '/dashboard/scheduled'] as $path) {
            foreach ([-1, 999999, 'invalid'] as $invalidId) {
                $this->getJson($path.'?'.http_build_query([
                    'period' => 'day', 'period_at' => now()->toIso8601String(), 'barber_id' => $invalidId,
                ]))->assertUnprocessable();
            }
        }

    }

    public function test_services_timeline_counts_snapshot_items_and_legacy_services_with_zero_filled_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->completedAppointment($shop, $admin);
        $appointment->update(['start_time' => '2026-11-09 03:30:00']);
        $firstId = $appointment->service_id;
        $second = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Barba', 'description' => 'Barba',
            'duration' => 20, 'price' => '30.00', 'is_active' => true,
        ]);
        $appointment->checkout->update(['services' => [
            ['id' => $firstId, 'name' => 'Corte histórico', 'duration' => 40, 'price_cents' => 5000],
            ['id' => $second->id, 'name' => 'Barba histórica', 'duration' => 20, 'price_cents' => 3000],
        ]]);
        $appointment->service->update(['name' => 'Nome alterado']);
        $appointment->service->delete();
        $second->delete();
        $legacy = $this->completedAppointment($shop, $admin, checkout: false);
        $legacy->services()->sync([$firstId]);
        $legacy->update(['start_time' => '2026-11-09 02:59:59']);
        $single = $this->completedAppointment($shop, $admin, checkout: false);
        $single->services()->detach();
        $single->update(['start_time' => '2026-09-11 03:00:00']);
        foreach (['scheduled', 'cancelled', 'no_show'] as $status) {
            $this->completedAppointment($shop, $admin, checkout: false)->update([
                'start_time' => '2026-11-09 10:00:00', 'status' => $status,
            ]);
        }
        $this->completedAppointment($shop, $admin, checkout: false)->update(['start_time' => '2026-09-11 02:59:59']);
        $this->completedAppointment($shop, $admin, checkout: false)->update(['start_time' => '2026-11-10 03:00:00']);
        $deleted = $this->completedAppointment($shop, $admin, checkout: false);
        $deleted->update(['start_time' => '2026-11-09 10:00:00']);
        $deleted->delete();

        $response = $this->actingAs($admin)->get('/dashboard?unit=Inexistente');
        $response->assertInertia(fn (Assert $page) => $page->has('servicesTimeline.dates', 60)
            ->where('servicesTimeline.dates.0', '2026-09-11')
            ->where('servicesTimeline.dates.59', '2026-11-09')
            ->where('servicesTimeline.timezone', 'America/Sao_Paulo')->has('servicesTimeline.series', 3)
            ->where('appointments.total', 0));
        $timeline = $response->viewData('page')['props']['servicesTimeline'];
        $series = collect($timeline['series'])->keyBy('id');
        $this->assertSame(1, $series[$firstId]['counts'][59]);
        $this->assertSame('Corte histórico', $series[$firstId]['name']);
        $this->assertSame(1, $series[$firstId]['counts'][58]);
        $this->assertSame(2, array_sum($series[$firstId]['counts']));
        $this->assertSame(1, $series[$second->id]['counts'][59]);
        $this->assertSame(1, $series[$single->service_id]['counts'][0]);
        foreach ($series as $service) {
            $this->assertCount(60, $service['counts']);
            foreach ([7, 15, 30, 60] as $days) {
                $this->assertCount($days, array_slice($service['counts'], -$days));
            }
        }
        foreach ([
            [$firstId, '2026-11-09', $appointment->id],
            [$second->id, '2026-11-09', $appointment->id],
            [$firstId, '2026-11-08', $legacy->id],
            [$single->service_id, '2026-09-11', $single->id],
        ] as [$id, $day, $expectedId]) {
            $this->get('/dashboard?'.http_build_query(['service_id' => $id, 'chart_day' => $day]))
                ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 1)
                    ->where('appointments.data.0.id', $expectedId));
        }
        $this->get('/dashboard?service_id='.$second->id.'&chart_day=2026-11-08')
            ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0));
    }

    public function test_chart_filters_validate_and_preserve_pagination_and_timezone_alignment(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T02:45:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->completedAppointment($shop, $admin, checkout: false);
        for ($i = 0; $i < 11; $i++) {
            $appointment = $this->completedAppointment($shop, $admin, checkout: false);
            $appointment->services()->sync([$first->service_id]);
        }
        $query = ['service_id' => $first->service_id, 'chart_day' => '2026-11-09', 'per_page' => 10];
        $this->actingAs($admin)->get('/dashboard?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
            ->where('servicesTimeline.dates.59', '2026-11-09')
            ->where('servicesTimeline.series.0.counts.59', 12)
            ->where('appointments.total', 12)
            ->where('appointments.next_page_url', fn ($url) => str_contains($url, 'service_id='.$first->service_id)
                && str_contains($url, 'chart_day=2026-11-09')));
        $this->get('/dashboard?'.http_build_query([...$query, 'page' => 2]))->assertInertia(fn (Assert $page) => $page
            ->has('appointments.data', 2)->where('appointments.total', 12));
        foreach ([['service_id' => -1], ['service_id' => 'invalid'], ['chart_day' => 'invalid']] as $invalid) {
            $this->getJson('/dashboard?'.http_build_query($invalid))->assertUnprocessable();
        }
    }

    public function test_timeline_includes_only_scheduled_services_for_the_next_seven_local_days(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-10T02:45:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->completedAppointment($shop, $admin, checkout: false);
        $first->update(['status' => 'scheduled', 'start_time' => '2026-11-10 03:00:00']);
        foreach ([
            ['scheduled', '2026-11-17 02:59:59'],
            ['scheduled', '2026-11-17 03:00:00'],
            ['scheduled', '2026-11-10 02:59:59'],
            ['completed', '2026-11-11 12:00:00'],
            ['cancelled', '2026-11-11 12:00:00'],
            ['no_show', '2026-11-11 12:00:00'],
        ] as [$status, $start]) {
            $appointment = $this->completedAppointment($shop, $admin, checkout: false);
            $appointment->services()->sync([$first->service_id]);
            $appointment->update(['status' => $status, 'start_time' => $start]);
        }
        $deleted = $this->completedAppointment($shop, $admin, checkout: false);
        $deleted->update(['status' => 'scheduled', 'start_time' => '2026-11-11 12:00:00']);
        $deleted->delete();
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('servicesTimeline.dates.59', '2026-11-09')
            ->has('servicesTimeline.future_dates', 7)
            ->where('servicesTimeline.future_dates.0', '2026-11-10')
            ->where('servicesTimeline.future_dates.6', '2026-11-16')
            ->has('servicesTimeline.series', 1)
            ->where('servicesTimeline.series.0.id', $first->service_id)
            ->where('servicesTimeline.series.0.scheduled_counts', [1, 0, 0, 0, 0, 0, 1])
            ->where('servicesTimeline.series.0.counts', array_fill(0, 60, 0)));
    }

    public function test_barber_dashboard_scopes_all_surfaces_to_the_authenticated_professional(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $colleague = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $own = $this->completedAppointment($shop, $barber);
        $own->update(['start_time' => '2026-11-09 12:00:00']);
        $acrossUnits = $this->completedAppointment($other, $barber, checkout: false);
        $acrossUnits->update(['start_time' => '2026-11-09 13:00:00']);
        $foreign = $this->completedAppointment($shop, $colleague);
        // Even a shared client must not expose another professional's appointment.
        $foreign->update(['client_id' => $own->client_id, 'start_time' => '2026-11-09 12:00:00', 'notes' => 'Outro barbeiro']);
        $scheduled = $this->completedAppointment($other, $barber, checkout: false);
        $scheduled->update(['status' => 'scheduled', 'start_time' => '2026-11-09 15:00:00']);
        $future = $this->completedAppointment($shop, $barber, checkout: false);
        $future->update(['status' => 'scheduled', 'start_time' => '2026-11-10 15:00:00']);
        $this->completedAppointment($shop, $colleague, checkout: false)->update(['status' => 'scheduled']);

        $this->actingAs($barber)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 2)->where('appointments.data.0.id', $acrossUnits->id)
            ->where('appointments.data.1.id', $own->id)
            ->where('appointments.data.1.checkout', null)->missing('appointments.data.1.services.0.price_cents')
            ->has('indicators.barbers', 0)
            ->missing('indicators.periods.0.financials')
            ->where('indicators.periods.0', ['period' => 'day', 'completed' => 2, 'scheduled' => 1, 'total' => 3])
            ->where('indicators.periods.1', ['period' => 'week', 'completed' => 2, 'scheduled' => 2, 'total' => 4])
            ->has('servicesTimeline.series', 3));
        $query = ['period' => 'day', 'period_at' => now()->toIso8601String()];
        $this->getJson('/dashboard/scheduled?'.http_build_query($query))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $scheduled->id)
            ->assertJsonMissingPath('data.0.expected_cents');
        $this->get('/dashboard?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 2));
        $this->get('/dashboard?chart_day=2026-11-09&service_id='.$own->service_id)
            ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 1)->where('appointments.data.0.id', $own->id));
        foreach ([
            ['id' => $foreign->id], ['services' => 'inexistente'], ['notes' => 'Outro barbeiro'],
            ['service_id' => $foreign->service_id], ['barber' => $colleague->name],
        ] as $filter) {
            $this->get('/dashboard?'.http_build_query($filter))->assertInertia(fn (Assert $page) => $page
                ->where('appointments.total', 0));
        }
        foreach (['/dashboard', '/dashboard/scheduled'] as $path) {
            $this->getJson($path.'?'.http_build_query([...$query, 'barber_id' => $colleague->id]))->assertUnprocessable();
        }
        foreach (['total_min' => 0, 'products' => 'Óleo', 'payment_method' => 'pix'] as $key => $value) {
            $this->getJson('/dashboard?'.http_build_query([$key => $value]))->assertUnprocessable();
        }
        $this->get('/dashboard', [
            'X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'appointments,indicators,servicesTimeline',
        ])->assertOk()->assertJsonPath('props.appointments.total', 2)
            ->assertJsonCount(0, 'props.indicators.barbers')->assertJsonCount(3, 'props.servicesTimeline.series')
            ->assertJsonMissingPath('props.indicators.periods.0.financials');
    }

    public function test_barber_dashboard_pagination_and_empty_state_never_fall_back_to_other_professionals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $barber = User::factory()->create(['role' => 'barber']);
        $colleague = User::factory()->create(['role' => 'barber']);
        for ($i = 0; $i < 12; $i++) {
            $this->completedAppointment($shop, $barber, checkout: false);
            $this->completedAppointment($shop, $barber, checkout: false)->update(['status' => 'scheduled']);
            $this->completedAppointment($shop, $colleague, checkout: false);
        }
        $this->actingAs($barber)->get('/dashboard?per_page=10&page=2')
            ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 12)->has('appointments.data', 2));
        $this->getJson('/dashboard/scheduled?'.http_build_query([
            'period' => 'day', 'period_at' => now()->toIso8601String(), 'page' => 2,
        ]))->assertOk()->assertJsonPath('total', 12)->assertJsonCount(2, 'data');
        $empty = User::factory()->create(['role' => 'barber']);
        $this->actingAs($empty)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('appointments.total', 0)->where('indicators.periods.0.total', 0)
            ->has('indicators.barbers', 0)
            ->has('servicesTimeline.series', 0));
    }

    public function test_dashboard_dates_share_sao_paulo_boundaries_without_changing_utc_storage(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->travelTo(CarbonImmutable::parse('2026-11-10T02:45:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $today = $this->completedAppointment($shop, $admin, checkout: false);
        $tomorrow = $this->completedAppointment($shop, $admin, checkout: false);
        $tomorrow->services()->sync([$today->service_id]);
        $tomorrow->update(['start_time' => '2026-11-10 03:00:00']);
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('indicators.periods.0.completed', 1)
            ->where('servicesTimeline.dates.59', '2026-11-09')
            ->where('servicesTimeline.series.0.counts.59', 1));
        foreach ([
            ['date_from' => '2026-11-09', 'date_to' => '2026-11-09'],
            ['chart_day' => '2026-11-09', 'service_id' => $today->service_id],
            ['period' => 'day', 'period_at' => now()->toIso8601String()],
        ] as $query) {
            $this->get('/dashboard?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page
                ->where('appointments.total', 1)->where('appointments.data.0.id', $today->id)
                ->where('appointments.data.0.start_time', '2026-11-10T02:30:00+00:00'));
        }
        $this->assertDatabaseHas('appointments', ['id' => $today->id, 'start_time' => '2026-11-10 02:30:00']);
    }

    public function test_financial_indicators_use_net_checkout_snapshots_and_flag_missing_checkouts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $barber = User::factory()->create(['role' => 'barber', 'name' => 'Ana']);
        $idle = User::factory()->create(['role' => 'barber', 'name' => 'Bruno']);
        $barber->barbershops()->attach([$shop->id, $other->id]);
        $first = $this->completedAppointment($shop, $barber);
        $second = $this->completedAppointment($other, $barber);
        $first->service->update(['price' => '999.99']);
        $first->services()->attach($second->service_id);
        $first->service->delete();
        $this->completedAppointment($shop, $barber, checkout: false);
        foreach (['scheduled', 'cancelled', 'no_show'] as $status) {
            $this->completedAppointment($shop, $barber)->update(['status' => $status]);
        }
        $this->completedAppointment($shop, $barber)->delete();
        $this->completedAppointment($shop, $barber)->update(['start_time' => '2026-10-30 12:00:00']);
        $financials = [
            'completed_cents' => 13000, 'scheduled_cents' => 5000, 'total_cents' => 18000,
            'missing_completed' => 1, 'missing_scheduled' => 0,
        ];
        $this->actingAs($admin)->get('/dashboard?unit=Inexistente&total_min=99999')
            ->assertInertia(fn (Assert $page) => $page->where('appointments.total', 0)
                ->where('indicators.periods.0.completed', 3)
                ->where('indicators.periods.0.scheduled', 1)
                ->where('indicators.periods.0.financials', $financials)
                ->where('indicators.periods.1.financials', $financials)
                ->where('indicators.periods.2.financials', $financials)
                ->where('indicators.barbers.0.id', $barber->id)
                ->where('indicators.barbers.0.periods.0.financials', $financials)
                ->where('indicators.barbers.1.id', $idle->id)
                ->where('indicators.barbers.1.periods.0.financials', [
                    'completed_cents' => 0, 'scheduled_cents' => 0, 'total_cents' => 0,
                    'missing_completed' => 0, 'missing_scheduled' => 0,
                ]));
    }

    public function test_scheduled_amounts_use_current_services_once_with_legacy_free_and_deleted_services(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $barber = User::factory()->create(['role' => 'barber']);
        $multi = $this->completedAppointment($other, $barber);
        $multi->update(['status' => 'scheduled']);
        $multi->service->update(['price' => '10.29']);
        $legacy = $this->completedAppointment($shop, $barber, checkout: false);
        $legacy->update(['status' => 'scheduled']);
        $legacy->service->update(['price' => '20.01']);
        $legacy->services()->detach();
        $multi->services()->attach($legacy->service_id);
        $legacy->service->delete();
        $free = $this->completedAppointment($shop, $barber, checkout: false);
        $free->update(['status' => 'scheduled']);
        $free->service->update(['price' => '0.00']);
        $free->service->delete();

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('indicators.periods.0.scheduled', 3)
            ->where('indicators.periods.0.financials', [
                'completed_cents' => 0, 'scheduled_cents' => 5031, 'total_cents' => 5031,
                'missing_completed' => 0, 'missing_scheduled' => 0,
            ]));
        $this->getJson('/dashboard/scheduled?'.http_build_query([
            'period' => 'day', 'period_at' => now()->toIso8601String(), 'barber_id' => $barber->id,
        ]))->assertOk()->assertJsonPath('data.0.expected_cents', 3030)
            ->assertJsonPath('data.1.expected_cents', 2001)
            ->assertJsonPath('data.2.expected_cents', 0);

        $multi->service->update(['price' => '99999999.99']);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('indicators.periods.0.financials.scheduled_cents', 10000004001));
    }

    public function test_scheduled_estimate_does_not_invent_zero_when_services_are_unavailable(): void
    {
        $query = Appointment::withoutGlobalScopes()
            ->fromSub(DB::query()->selectRaw('0 as id, 0 as service_id'), 'appointments')
            ->select('appointments.*');
        $appointment = app(DashboardAppointments::class)->withExpectedAmount($query)->firstOrFail();

        $this->assertNull($appointment->expected_cents);
    }

    public function test_financial_totals_match_barbers_and_all_pages_of_the_scheduled_popup(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin']);
        $barber = User::factory()->create(['role' => 'barber', 'name' => 'Ana']);
        $colleague = User::factory()->create(['role' => 'barber', 'name' => 'Bruno']);
        foreach ([$barber, $colleague] as $professional) {
            for ($i = 0; $i < 12; $i++) {
                $appointment = $this->completedAppointment($i % 2 ? $shop : $other, $professional, checkout: false);
                $appointment->update(['status' => 'scheduled']);
                $appointment->service->update(['price' => '0.29']);
            }
        }
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('indicators.periods.0.financials.scheduled_cents', 696)
            ->where('indicators.barbers.0.periods.0.financials.scheduled_cents', 348)
            ->where('indicators.barbers.1.periods.0.financials.scheduled_cents', 348));
        foreach (['day', 'week', 'month'] as $period) {
            $sum = 0;
            foreach ([1, 2] as $page) {
                $response = $this->getJson('/dashboard/scheduled?'.http_build_query([
                    'period' => $period, 'period_at' => now()->toIso8601String(),
                    'barber_id' => $barber->id, 'page' => $page,
                ]))->assertOk()->assertJsonPath('total', 12);
                $sum += array_sum(array_column($response->json('data'), 'expected_cents'));
            }
            $this->assertSame(348, $sum);
        }
    }

    public function test_custom_dashboard_range_filters_all_metrics_and_compares_equal_previous_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $other = $this->shop('Norte');
        $admin = User::factory()->create(['role' => 'admin']);
        $previous = $this->completedAppointment($shop, $admin);
        $previous->update(['start_time' => '2026-11-08 12:00:00']);
        $returning = $this->completedAppointment($shop, $admin);
        $returning->update(['client_id' => $previous->client_id, 'start_time' => '2026-11-09 12:00:00']);
        $new = $this->completedAppointment($shop, $admin);
        $new->update(['start_time' => '2026-11-09 14:00:00']);
        $this->completedAppointment($shop, $admin)->update([
            'status' => 'scheduled', 'start_time' => '2026-11-09 15:00:00',
        ]);
        $this->completedAppointment($shop, $admin)->update([
            'status' => 'cancelled', 'start_time' => '2026-11-09 16:00:00',
        ]);
        $this->completedAppointment($shop, $admin)->update([
            'status' => 'no_show', 'start_time' => '2026-11-09 17:00:00',
        ]);
        $this->completedAppointment($other, $admin)->update(['start_time' => '2026-11-09 18:00:00']);

        $query = http_build_query([
            'range_from' => '2026-11-09',
            'range_to' => '2026-11-09',
            'barbershop_id' => $shop->id,
        ]);
        $this->actingAs($admin)->get('/dashboard?'.$query)->assertInertia(fn (Assert $page) => $page
            ->where('summary.from', '2026-11-09')
            ->where('summary.to', '2026-11-09')
            ->where('summary.previous_from', '2026-11-08')
            ->where('summary.previous_to', '2026-11-08')
            ->where('summary.current.completed', 2)
            ->where('summary.current.scheduled', 1)
            ->where('summary.current.cancelled', 1)
            ->where('summary.current.no_show', 1)
            ->where('summary.current.retention.unique_clients', 2)
            ->where('summary.current.retention.new_clients', 1)
            ->where('summary.current.retention.returning_clients', 1)
            ->where('summary.previous.completed', 1)
            ->where('summary.current.financials.checkout_count', 2)
            ->where('summary.current.financials.average_ticket_cents', 6500)
            ->where('appointments.total', 2)
            ->where('appointments.data.0.id', $new->id)
            ->where('rangeTimeline.dates', ['2026-11-09']));
    }

    public function test_occupancy_uses_working_hours_and_subtracts_blocks_without_double_counting(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $shop->update([
            'opening_hours' => [
                'monday' => ['open' => '09:00', 'close' => '12:00', 'closed' => false],
            ],
        ]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $barber->barbershops()->attach($shop->id);
        WorkingHour::create([
            'user_id' => $barber->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '11:30',
        ]);
        $appointment = $this->completedAppointment($shop, $barber, checkout: false);
        $appointment->update([
            'start_time' => '2026-11-09 12:00:00',
            'end_time' => '2026-11-09 12:40:00',
        ]);
        BlockedTime::create([
            'user_id' => $barber->id,
            'start_datetime' => '2026-11-09 12:40:00',
            'end_datetime' => '2026-11-09 13:00:00',
            'reason' => 'Pausa',
        ]);

        $this->actingAs($barber)->get('/dashboard?'.http_build_query([
            'range_from' => '2026-11-09',
            'range_to' => '2026-11-09',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.current.capacity.available_minutes', 130)
            ->where('summary.current.capacity.occupied_minutes', 40)
            ->where('summary.current.capacity.free_minutes', 90)
            ->where('summary.current.capacity.occupancy_percent', 30.8)
            ->missing('summary.current.financials'));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/dashboard?'.http_build_query([
                'range_from' => '2026-11-09',
                'range_to' => '2026-11-09',
                'barbershop_id' => $shop->id,
            ]))->assertInertia(fn (Assert $page) => $page->where('summary.current.capacity', null));
    }

    public function test_occupancy_does_not_count_shop_hours_on_barbers_off_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $shop->update([
            'opening_hours' => [
                'monday' => ['open' => '09:00', 'close' => '12:00', 'closed' => false],
                'tuesday' => ['open' => '09:00', 'close' => '12:00', 'closed' => false],
            ],
        ]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $barber->barbershops()->attach($shop->id);
        WorkingHour::create([
            'user_id' => $barber->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '11:30',
        ]);

        $this->actingAs($barber)->get('/dashboard?'.http_build_query([
            'range_from' => '2026-11-09',
            'range_to' => '2026-11-10',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.current.capacity.available_minutes', 150)
            ->where('summary.current.capacity.occupied_minutes', 0)
            ->where('summary.current.capacity.free_minutes', 150));
    }

    public function test_occupancy_includes_partial_local_days_for_units_in_other_timezones(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $shop->update([
            'timezone' => 'Asia/Tokyo',
            'opening_hours' => [
                'monday' => ['open' => '09:00', 'close' => '12:00', 'closed' => false],
                'tuesday' => ['open' => '09:00', 'close' => '12:00', 'closed' => false],
            ],
        ]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $barber->barbershops()->attach($shop->id);
        WorkingHour::create([
            'user_id' => $barber->id, 'day_of_week' => 2,
            'start_time' => '09:00', 'end_time' => '12:00',
        ]);

        $this->actingAs($barber)->get('/dashboard?'.http_build_query([
            'range_from' => '2026-11-09',
            'range_to' => '2026-11-09',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.current.capacity.available_minutes', 180)
            ->where('summary.current.capacity.occupied_minutes', 0));
    }

    public function test_dashboard_rejects_ranges_longer_than_365_days(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/dashboard?'.http_build_query([
                'range_from' => '2025-11-08',
                'range_to' => '2026-11-08',
            ]))->assertUnprocessable()->assertJsonValidationErrors('range_to');
    }

    public function test_xlsx_export_respects_filters_and_keeps_user_text_as_plain_text(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->completedAppointment($shop, $admin);
        $appointment->client->update(['name' => '=HYPERLINK("https://example.test")']);
        $appointment->checkout->update([
            'services_total_cents' => 123456, 'products_total_cents' => 789,
            'discount_cents' => 0, 'total_cents' => 124245,
        ]);
        $outside = $this->completedAppointment($this->shop('Norte'), $admin);
        $outside->update(['start_time' => '2026-10-10 12:00:00']);

        $response = $this->actingAs($admin)->get('/dashboard/export?'.http_build_query([
            'range_from' => '2026-11-09',
            'range_to' => '2026-11-09',
            'barbershop_id' => $shop->id,
        ]))->assertOk()->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $path = tempnam(sys_get_temp_dir(), 'dashboard-xlsx-');
        file_put_contents($path, $response->streamedContent());
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('Nº', $sheet->getCell('A1')->getValue());
            $this->assertSame('Total do fechamento', $sheet->getCell('O1')->getValue());
            foreach (['L2' => 1234.56, 'M2' => 7.89, 'N2' => 0, 'O2' => 1242.45] as $coordinate => $amount) {
                $cell = $sheet->getCell($coordinate);
                $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
                $this->assertEquals($amount, $cell->getValue());
                $this->assertSame('[$R$-416] #,##0.00', $cell->getStyle()->getNumberFormat()->getFormatCode());
            }
            $decimal = StringHelper::getDecimalSeparator();
            $thousands = StringHelper::getThousandsSeparator();
            try {
                StringHelper::setDecimalSeparator(',');
                StringHelper::setThousandsSeparator('.');
                $this->assertSame('R$ 1.234,56', $sheet->getCell('L2')->getFormattedValue());
                $this->assertSame('R$ 7,89', $sheet->getCell('M2')->getFormattedValue());
                $this->assertSame('R$ 0,00', $sheet->getCell('N2')->getFormattedValue());
                $this->assertSame('R$ 1.242,45', $sheet->getCell('O2')->getFormattedValue());
            } finally {
                StringHelper::setDecimalSeparator($decimal);
                StringHelper::setThousandsSeparator($thousands);
            }
            $this->assertSame('PIX', $sheet->getCell('P2')->getValue());
            $this->assertSame((string) $appointment->id, $sheet->getCell('A2')->getValue());
            $this->assertSame('=HYPERLINK("https://example.test")', $sheet->getCell('D2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType());
            $this->assertSame(2, $sheet->getHighestRow());
        } finally {
            unlink($path);
        }
    }

    public function test_xlsx_export_distinguishes_zero_amounts_from_missing_checkouts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $admin = User::factory()->create(['role' => 'admin']);
        $zero = $this->completedAppointment($shop, $admin);
        $zero->checkout->update([
            'services_total_cents' => 0, 'products_total_cents' => 0,
            'discount_cents' => 0, 'total_cents' => 0,
        ]);
        $legacy = $this->completedAppointment($shop, $admin, false);
        $response = $this->actingAs($admin)->get('/dashboard/export?'.http_build_query([
            'range_from' => '2026-11-09', 'range_to' => '2026-11-09',
        ]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dashboard-xlsx-');
        file_put_contents($path, $response->streamedContent());
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame((string) $legacy->id, $sheet->getCell('A2')->getValue());
            $this->assertSame((string) $zero->id, $sheet->getCell('A3')->getValue());
            foreach (['L', 'M', 'N', 'O'] as $column) {
                $this->assertNull($sheet->getCell($column.'2')->getValue());
                $this->assertEquals(0, $sheet->getCell($column.'3')->getValue());
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($column.'3')->getDataType());
                $this->assertSame('[$R$-416] #,##0.00', $sheet->getCell($column.'3')->getStyle()->getNumberFormat()->getFormatCode());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_barber_xlsx_export_contains_only_own_rows_and_no_financial_columns(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09T12:00:00+00:00'));
        $shop = $this->shop();
        $barber = User::factory()->create(['role' => 'barber']);
        $colleague = User::factory()->create(['role' => 'barber']);
        $own = $this->completedAppointment($shop, $barber);
        $this->completedAppointment($shop, $colleague);

        $response = $this->actingAs($barber)->get('/dashboard/export?'.http_build_query([
            'range_from' => '2026-11-09',
            'range_to' => '2026-11-09',
        ]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dashboard-xlsx-');
        file_put_contents($path, $response->streamedContent());
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('Observações do agendamento', $sheet->getCell('J1')->getValue());
            $this->assertSame('Nº', $sheet->getCell('A1')->getValue());
            $this->assertSame((string) $own->id, $sheet->getCell('A2')->getValue());
            $this->assertSame('J', $sheet->getHighestColumn());
            $this->assertSame(2, $sheet->getHighestRow());
        } finally {
            unlink($path);
        }
    }
}
