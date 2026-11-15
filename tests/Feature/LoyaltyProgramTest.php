<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentCheckout;
use App\Models\Barbershop;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\ClientPortalGrant;
use App\Models\LoyaltyAccount;
use App\Models\Scopes\BarbershopScope;
use App\Models\Service;
use App\Models\User;
use App\Services\LoyaltyProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoyaltyProgramTest extends TestCase
{
    use RefreshDatabase;

    private Barbershop $shop;

    private User $admin;

    private User $barber;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Barbershop::create([
            'name' => 'Centro',
            'slug' => 'centro',
            'loyalty_enabled' => true,
            'loyalty_stamp_goal' => 2,
            'loyalty_reward_description' => 'Um corte grátis',
            'loyalty_enabled_at' => now()->subDay(),
        ]);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'barbershop_id' => $this->shop->id,
        ]);
        $this->barber = User::factory()->create([
            'role' => 'barber',
            'barbershop_id' => $this->shop->id,
        ]);
        $this->service = Service::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Corte',
            'description' => 'Corte tradicional',
            'duration' => 30,
            'price' => '50.00',
            'is_active' => true,
        ]);
        CashRegister::create([
            'barbershop_id' => $this->shop->id,
            'opened_by' => $this->admin->id,
            'opening_amount_cents' => 0,
            'opened_at' => now(),
        ]);
    }

    private function checkout(string $email, int $discountCents = 0, ?Barbershop $shop = null): Client
    {
        $shop ??= $this->shop;
        $service = Service::withoutGlobalScope(BarbershopScope::class)
            ->where('barbershop_id', $shop->id)->firstOrFail();
        if (! CashRegister::where('barbershop_id', $shop->id)->whereNull('closed_at')->exists()) {
            CashRegister::create([
                'barbershop_id' => $shop->id,
                'opened_by' => $this->admin->id,
                'opening_amount_cents' => 0,
                'opened_at' => now(),
            ]);
        }
        $client = Client::create([
            'barbershop_id' => $shop->id,
            'name' => 'Cliente',
            'email' => $email,
            'phone' => '+5511999999999',
        ]);
        $start = now()->subHour();
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id,
            'client_id' => $client->id,
            'user_id' => $this->barber->id,
            'service_id' => $service->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'status' => 'scheduled',
        ]);
        $version = hash('sha256', implode('|', [
            $appointment->start_time->toIso8601String(),
            $appointment->end_time->toIso8601String(),
            $appointment->status,
            $appointment->updated_at->toIso8601String(),
        ]));

        $this->actingAs($this->admin)->postJson("/schedule/appointments/$appointment->id/checkout", [
            'barbershop_id' => $shop->id,
            'version' => $version,
            'services_total_cents' => 5000,
            'services' => [['id' => $service->id, 'price_cents' => 5000]],
            'products' => [],
            'discount_cents' => $discountCents,
            'payment_method' => 'cash',
        ])->assertOk();

        return $client;
    }

    public function test_paid_checkouts_accumulate_by_unit_and_email_across_duplicate_client_records(): void
    {
        $first = $this->checkout('Cliente@example.com');
        $second = $this->checkout('cliente@EXAMPLE.com');
        $this->checkout('outra@example.com');

        $this->assertDatabaseCount('loyalty_accounts', 2);
        $account = LoyaltyAccount::where('barbershop_id', $this->shop->id)
            ->where('stamps_balance', 2)->firstOrFail();
        $this->assertSame(2, $account->transactions()->where('type', 'earned')->count());
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'client_id' => $first->id,
            'type' => 'earned',
            'stamps' => 1,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'client_id' => $second->id,
            'type' => 'earned',
            'stamps' => 1,
        ]);
        $appointment = Appointment::where('client_id', $first->id)->firstOrFail();
        app(LoyaltyProgram::class)->award(
            $appointment,
            AppointmentCheckout::where('appointment_id', $appointment->id)->firstOrFail(),
        );
        $this->assertSame(2, $account->fresh()->stamps_balance);
    }

    public function test_same_email_keeps_separate_balances_in_each_unit(): void
    {
        $otherShop = Barbershop::create([
            'name' => 'Norte',
            'slug' => 'norte',
            'loyalty_enabled' => true,
            'loyalty_stamp_goal' => 3,
            'loyalty_reward_description' => 'Uma barba grátis',
            'loyalty_enabled_at' => now()->subDay(),
        ]);
        Service::create([
            'barbershop_id' => $otherShop->id,
            'name' => 'Barba',
            'description' => 'Barba',
            'duration' => 30,
            'price' => '50.00',
            'is_active' => true,
        ]);

        $this->checkout('cliente@example.com');
        $this->checkout('cliente@example.com', shop: $otherShop);

        $balances = LoyaltyAccount::orderBy('barbershop_id')->pluck('stamps_balance')->all();
        $this->assertSame([1, 1], $balances);
    }

    public function test_zero_total_and_disabled_program_checkouts_do_not_award_stamps(): void
    {
        $this->checkout('gratis@example.com', 5000);
        $this->assertDatabaseCount('loyalty_accounts', 0);

        $this->shop->update(['loyalty_enabled' => false]);
        $this->checkout('pausado@example.com');
        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_pre_activation_checkouts_do_not_receive_stamps_retroactively(): void
    {
        $this->shop->update(['loyalty_enabled' => false]);
        $client = Client::create([
            'barbershop_id' => $this->shop->id,
            'name' => 'Cliente',
            'email' => 'anterior@example.com',
            'phone' => '+5511999999999',
        ]);
        $start = now()->subHour();
        $appointment = Appointment::create([
            'barbershop_id' => $this->shop->id,
            'client_id' => $client->id,
            'user_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'status' => 'completed',
        ]);
        $checkout = AppointmentCheckout::create([
            'appointment_id' => $appointment->id,
            'cash_register_id' => CashRegister::firstOrFail()->id,
            'created_by' => $this->admin->id,
            'services' => [['id' => $this->service->id, 'name' => 'Corte']],
            'products' => [],
            'services_total_cents' => 5000,
            'products_total_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => 5000,
            'payment_method' => 'cash',
            'created_at' => now()->subMinute(),
        ]);
        $this->travel(1)->minutes();
        $this->shop->update(['loyalty_enabled' => true, 'loyalty_enabled_at' => now()]);

        app(LoyaltyProgram::class)->award($appointment, $checkout);

        $this->assertDatabaseCount('loyalty_accounts', 0);
        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_admin_can_redeem_a_reward_and_client_portal_shows_unit_progress(): void
    {
        $this->checkout('cliente@example.com');
        $this->checkout('CLIENTE@example.com');
        $third = $this->checkout('cliente@example.com');
        $this->actingAs($this->admin)->get("/clients/$third->id")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.goal', 2)
            ->where('loyalty.stampsBalance', 3)
            ->where('loyalty.rewardsAvailable', 1)
            ->where('loyalty.reward', 'Um corte grátis'));

        $this->post("/clients/$third->id/loyalty/redeem")->assertRedirect("/clients/$third->id");
        $account = LoyaltyAccount::where('barbershop_id', $this->shop->id)->firstOrFail();
        $this->assertSame(1, $account->fresh()->stamps_balance);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_account_id' => $account->id,
            'client_id' => $third->id,
            'created_by' => $this->admin->id,
            'type' => 'redeemed',
            'stamps' => -2,
            'reward_description' => 'Um corte grátis',
        ]);
        $this->post("/clients/$third->id/loyalty/redeem")
            ->assertSessionHasErrors('loyalty');

        $token = str_repeat('a', 64);
        ClientPortalGrant::create([
            'email_key' => hash_hmac('sha256', 'cliente@example.com', config('app.key')),
            'email' => 'cliente@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);
        $this->get("/area-do-cliente/$token")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.loyalty.0.barbershop', 'Centro')
                ->where('dashboard.loyalty.0.stampsBalance', 1)
                ->where('dashboard.loyalty.0.rewardsAvailable', 0));

        $this->actingAs($this->barber)->post("/clients/$third->id/loyalty/redeem")
            ->assertForbidden();
    }
}
