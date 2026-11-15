<?php

namespace Tests\Feature;

use App\Mail\NotificationEmail;
use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\ClientPortalGrant;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Services\EmailDelivery;
use App\Services\EmailDeliveryFailure;
use App\Services\RetryEmailNotification;
use App\Services\SmtpGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));
        config(['notifications.email_enabled' => true, 'mail.default' => 'smtp']);
        Queue::fake();
    }

    private function appointment(string $email = 'client@example.com', string $status = 'completed', int $hours = -24): Appointment
    {
        $number = Barbershop::count() + 1;
        $shop = Barbershop::create([
            'name' => "Unidade $number", 'slug' => "unidade-$number",
            'timezone' => 'America/Sao_Paulo', 'address' => 'Rua Central',
            'phones' => ['+5511999999999'],
        ]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id, 'name' => "Barbeiro $number"]);
        $service = Service::create([
            'barbershop_id' => $shop->id, 'name' => "Corte $number", 'description' => 'Corte',
            'duration' => 40, 'price' => 50, 'is_active' => true,
        ]);
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Cliente', 'email' => $email, 'phone' => '+5511999999999',
        ]);
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id, 'client_id' => $client->id, 'user_id' => $barber->id,
            'service_id' => $service->id, 'start_time' => now()->addHours($hours),
            'end_time' => now()->addHours($hours)->addMinutes(40), 'status' => $status,
        ]);
        $appointment->services()->attach($service);

        return $appointment;
    }

    private function requestLink(string $email = 'client@example.com'): Notification
    {
        $this->post('/area-do-cliente', ['email' => $email])->assertRedirect('/')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Se houver um cadastro e não existir um link válido, enviaremos as instruções por e-mail. Verifique sua caixa de entrada e spam.');

        return Notification::where('template', 'client_portal_access')->latest('id')->firstOrFail();
    }

    public function test_request_is_neutral_and_email_availability_is_explicit(): void
    {
        $this->post('/area-do-cliente', ['email' => 'unknown@example.com'])->assertRedirect('/')
            ->assertSessionHas('inertia.flash_data.toast.message', 'Se houver um cadastro e não existir um link válido, enviaremos as instruções por e-mail. Verifique sua caixa de entrada e spam.');
        $this->assertDatabaseCount('client_portal_grants', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->post('/area-do-cliente', ['email' => 'invalid'])->assertSessionHasErrors('email');
        config(['notifications.email_enabled' => false]);
        $this->post('/area-do-cliente', ['email' => 'another@example.com'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('notifications', 0);
        config(['notifications.email_enabled' => true, 'mail.default' => 'log']);
        $this->post('/area-do-cliente', ['email' => 'log@example.com'])->assertSessionHasErrors('email');
    }

    public function test_link_is_encrypted_in_email_and_valid_for_one_hour(): void
    {
        $this->appointment();
        $notification = $this->requestLink('  CLIENT@example.com ');
        $url = $notification->secret['url'];
        $token = basename(parse_url($url, PHP_URL_PATH));
        $grant = ClientPortalGrant::firstOrFail();
        $this->assertSame(hash('sha256', $token), $grant->token_hash);
        $this->assertStringNotContainsString('client@example.com', $grant->getRawOriginal('email'));
        $this->assertStringNotContainsString($token, $notification->getRawOriginal('secret'));
        $mail = new NotificationEmail($notification);
        $this->assertStringContainsString($url, $mail->render());
        $this->assertStringContainsString($url, view($mail->textView(), $mail->data())->render());
        config(['mail.default' => 'array']);
        app(EmailDelivery::class)->send($notification->id);
        $this->assertSame('simulated', $notification->fresh()->status);
        $this->assertStringNotContainsString($token, $notification->fresh()->getRawOriginal('message'));
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('ClientPortal')->where('valid', true));
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('valid', true));
        $this->travel(60)->minutes();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('valid', false)->where('dashboard', null));
        $this->get('/area-do-cliente/'.str_repeat('a', 64))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    }

    public function test_duplicate_requests_are_blocked_until_expiry_and_failure_rules_are_respected(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->appointment();
        $first = $this->requestLink();
        $this->requestLink('CLIENT@example.com');
        $this->assertDatabaseCount('notifications', 1);
        $first->update(['status' => 'failed', 'failure_code' => 'smtp_result_unknown']);
        $this->requestLink();
        $this->assertDatabaseCount('notifications', 1);
        $first->update(['status' => 'pending', 'failure_code' => 'smtp_temporary_rejection']);
        $this->requestLink();
        $this->assertDatabaseCount('notifications', 1);
        $first->update(['status' => 'failed', 'failure_code' => 'smtp_rejected']);
        $second = $this->requestLink();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($first));
        $this->get($first->secret['url'])->assertInertia(fn (Assert $page) => $page->where('valid', false));
        $this->assertSame('superseded', app(EmailDelivery::class)->ineligible($first));
        $this->get($second->secret['url'])->assertInertia(fn (Assert $page) => $page->where('valid', true));
        $this->travel(60)->minutes();
        $third = $this->requestLink();
        $this->assertDatabaseCount('notifications', 3);
        $this->assertNotSame($second->secret['url'], $third->secret['url']);
    }

    public function test_processing_delivery_cannot_be_replaced_even_after_expiration(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->appointment();
        $first = $this->requestLink();
        $first->update(['status' => 'processing']);
        $this->travel(60)->minutes();
        $this->requestLink();
        $this->assertDatabaseCount('notifications', 1);
        $first->update(['status' => 'failed', 'failure_code' => 'reconciliation_required']);
        $this->requestLink();
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_definitive_smtp_failure_allows_a_new_link_but_cannot_be_retried(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->appointment();
        $first = $this->requestLink();
        $this->mock(SmtpGateway::class, fn ($mock) => $mock->shouldReceive('send')->once()
            ->andThrow(new EmailDeliveryFailure('smtp_rejected', false)));
        app(EmailDelivery::class)->send($first->id);
        $this->assertSame('smtp_rejected', $first->fresh()->failure_code);
        $second = $this->requestLink();
        $this->assertNotSame($first->secret['url'], $second->secret['url']);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($first->fresh()));
        app(EmailDelivery::class)->send($first->id);
        $this->get($first->secret['url'])->assertInertia(fn (Assert $page) => $page->where('valid', false));
    }

    public function test_dashboard_matches_current_active_clients_and_all_statuses_across_units(): void
    {
        $first = $this->appointment('CLIENT@example.com');
        $future = $this->appointment('client@example.com', 'scheduled', 24);
        $cancelled = $this->appointment('client@example.com', 'cancelled');
        $other = $this->appointment('other@example.com');
        $deleted = $this->appointment();
        $deleted->delete();
        $archived = $this->appointment();
        $archived->client->delete();
        $legacy = $this->appointment();
        $legacy->services()->detach();
        $this->actingAs($other->barber);
        $url = $this->requestLink()->secret['url'];
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('valid', true)
            ->where('dashboard.totalCompleted', 2)
            ->where('dashboard.favoriteService', 'Corte 1')
            ->where('dashboard.favoriteBarber', 'Barbeiro 1')
            ->has('dashboard.upcoming', 1)->where('dashboard.upcoming.0.id', $future->id)
            ->where('dashboard.upcoming.0.starts_at', '16/11/2026 09:00')
            ->has('dashboard.history.data', 4));
        Client::withoutGlobalScopes()->findOrFail($first->client_id)->update(['email' => 'changed@example.com']);
        Client::withoutGlobalScopes()->findOrFail($cancelled->client_id)->delete();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('dashboard.totalCompleted', 1)
            ->has('dashboard.history.data', 2));
    }

    public function test_email_and_ip_limits_apply_to_unknown_addresses(): void
    {
        $this->post('/area-do-cliente', ['email' => 'unknown@example.com'])->assertSessionHasNoErrors();
        $this->post('/area-do-cliente', ['email' => 'UNKNOWN@example.com'])->assertSessionHasErrors('email');
        for ($i = 0; $i < 4; $i++) {
            $this->post('/area-do-cliente', ['email' => "other$i@example.com"])->assertSessionHasNoErrors();
        }
        $this->post('/area-do-cliente', ['email' => 'last@example.com'])->assertSessionHasErrors('email');
    }

    public function test_completed_multi_service_counts_and_pagination(): void
    {
        $first = $this->appointment();
        $secondService = Service::create([
            'barbershop_id' => $first->barbershop_id, 'name' => 'Barba', 'description' => 'Barba',
            'duration' => 30, 'price' => 30, 'is_active' => true,
        ]);
        $first->services()->attach($secondService);
        for ($i = 0; $i < 16; $i++) {
            $item = $first->replicate();
            $item->status = 'cancelled';
            $item->start_time = now()->subDays($i + 2);
            $item->end_time = $item->start_time->addMinutes(40);
            $item->save();
        }
        $url = $this->requestLink()->secret['url'];
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('dashboard.totalCompleted', 1)
            ->where('dashboard.favoriteService', 'Barba')->has('dashboard.history.data', 15)
            ->where('dashboard.history.total', 17));
        $this->get($url.'?page=2')->assertInertia(fn (Assert $page) => $page->has('dashboard.history.data', 2)
            ->where('dashboard.history.current_page', 2));
    }

    public function test_empty_indicators_and_page_are_available_to_active_clients_without_reservations(): void
    {
        $appointment = $this->appointment();
        $appointment->delete();
        $url = $this->requestLink()->secret['url'];
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('dashboard.totalCompleted', 0)
            ->where('dashboard.favoriteService', null)->where('dashboard.favoriteBarber', null)
            ->has('dashboard.upcoming', 0)->has('dashboard.history.data', 0));
    }

    public function test_link_throttle_also_preserves_privacy_headers(): void
    {
        $url = '/area-do-cliente/'.str_repeat('a', 64);
        for ($i = 0; $i < 60; $i++) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
