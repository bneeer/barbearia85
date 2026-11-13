<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentCheckout;
use App\Models\Barbershop;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_populates_existing_units_with_consistent_data_and_can_be_repeated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 14:00', 'America/Sao_Paulo')->utc());
        $shop = Barbershop::create([
            'name' => 'Unidade existente', 'slug' => 'existente', 'timezone' => 'America/Sao_Paulo',
            'is_open' => true,
            'opening_hours' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                ['open' => '09:00', 'close' => '19:00', 'closed' => false],
            ),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $shop->id]);
        $other = Barbershop::create([
            'name' => 'Unidade fechada', 'slug' => 'fechada', 'timezone' => 'UTC', 'is_open' => false,
        ]);
        $shopBefore = $shop->fresh()->getAttributes();
        $service = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Corte social na tesoura', 'duration' => 40,
            'price' => 75, 'description' => 'Manter cadastro original', 'is_active' => true,
        ]);

        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseCount('barbershops', 2);
        $this->assertSame($shopBefore, $shop->fresh()->getAttributes());
        $this->assertSame('75.00', $service->fresh()->price);
        $this->assertSame('Manter cadastro original', $service->fresh()->description);
        $this->assertSame(5, $shop->users()->where('role', 'barber')->count());
        $this->assertSame(5, $other->users()->where('role', 'barber')->count());
        $this->assertSame(8, $shop->services()->count());
        $this->assertSame(60, $shop->clients()->count());
        $this->assertSame(4, $shop->products()->count());
        $this->assertSame(0, $other->appointments()->count());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('payments', 0);

        $appointments = $shop->appointments()->with(['services', 'checkout', 'barber.workingHours'])->get();
        $this->assertGreaterThan(1000, $appointments->count());
        $this->assertEqualsCanonicalizing(
            ['scheduled', 'completed', 'cancelled', 'no_show'],
            $appointments->pluck('status')->unique()->all(),
        );
        $this->assertTrue($appointments->contains(fn ($item) => $item->start_time->isFuture()));
        $this->assertTrue($appointments->contains(fn ($item) => $item->start_time->isToday() && $item->status === 'completed'));
        $this->assertTrue($appointments->contains(fn ($item) => $item->services->count() > 1));

        foreach ($appointments as $appointment) {
            $start = $appointment->start_time->toImmutable()->setTimezone($shop->timezone);
            $end = $appointment->end_time->toImmutable()->setTimezone($shop->timezone);
            $this->assertNotSame(0, $start->dayOfWeek);
            $this->assertGreaterThanOrEqual('09:00', $start->format('H:i'));
            $this->assertLessThanOrEqual('19:00', $end->format('H:i'));
            $this->assertSame((int) $appointment->services->sum('duration'), (int) $start->diffInMinutes($end));
            $this->assertTrue($appointment->services->every(fn ($item) => $item->barbershop_id === $shop->id));
            $this->assertTrue($appointment->barber->workingHours->contains(
                fn ($work) => (int) $work->day_of_week === $start->dayOfWeek,
            ));

            if ($appointment->status === 'completed') {
                $this->assertTrue($end->lte(now(new \DateTimeZone('America/Sao_Paulo'))));
                $checkout = $appointment->checkout;
                $this->assertNotNull($checkout);
                $this->assertSame($admin->id, $checkout->created_by);
                $this->assertSame(collect($checkout->services)->sum('price_cents'), $checkout->services_total_cents);
                $this->assertSame(
                    collect($checkout->products)->sum(fn ($item) => $item['quantity'] * $item['price_cents']),
                    $checkout->products_total_cents,
                );
                $this->assertSame(
                    $checkout->services_total_cents + $checkout->products_total_cents - $checkout->discount_cents,
                    $checkout->total_cents,
                );
            } else {
                $this->assertNull($appointment->checkout);
            }
        }

        foreach ($appointments->groupBy('user_id') as $bookings) {
            $previous = null;
            foreach ($bookings->where('status', '!=', 'cancelled')->sortBy('start_time') as $booking) {
                if ($previous) {
                    $this->assertTrue($previous->end_time->lte($booking->start_time));
                }
                $previous = $booking;
            }
        }

        $this->assertSame(4, AppointmentCheckout::distinct()->count('payment_method'));
        $this->assertTrue(AppointmentCheckout::where('discount_cents', '>', 0)->exists());
        $this->assertTrue(AppointmentCheckout::where('products_total_cents', '>', 0)->exists());

        $counts = $this->counts();
        $existing = $appointments->firstWhere('status', 'scheduled');
        $existing->update(['status' => 'cancelled']);
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($counts, $this->counts());
        $this->assertSame('cancelled', $existing->fresh()->status);

        $barber = $shop->users()->first();
        $blockedStart = CarbonImmutable::parse('2026-12-01 09:00', $shop->timezone)->utc();
        BlockedTime::create([
            'user_id' => $barber->id, 'start_datetime' => $blockedStart,
            'end_datetime' => $blockedStart->addHours(10), 'reason' => 'Ausencia programada',
        ]);
        $this->travel(1)->days();
        $this->seed(DemoDataSeeder::class);
        $this->assertFalse(Appointment::where('user_id', $barber->id)
            ->where('start_time', '<', $blockedStart->addHours(10))
            ->where('end_time', '>', $blockedStart)->exists());
        $this->assertGreaterThan($counts['appointments'], Appointment::count());
    }

    public function test_it_refuses_to_create_units_when_no_units_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cadastre uma unidade e um administrador');

        try {
            $this->seed(DemoDataSeeder::class);
        } finally {
            $this->assertDatabaseCount('barbershops', 0);
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_it_refuses_to_seed_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('somente em local ou testing');
        $this->app->make(DemoDataSeeder::class)->run();
    }

    private function counts(): array
    {
        return [
            'users' => User::count(), 'clients' => Client::count(), 'services' => Service::count(),
            'products' => Product::count(), 'appointments' => Appointment::count(),
            'checkouts' => AppointmentCheckout::count(),
        ];
    }
}
