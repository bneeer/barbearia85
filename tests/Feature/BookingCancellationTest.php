<?php

namespace Tests\Feature;

use App\Mail\NotificationEmail;
use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\BookingCancellationRequest;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingCancellations;
use App\Services\BookingNotifications;
use App\Services\EmailDelivery;
use App\Services\EmailOutbox;
use App\Services\RetryEmailNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use RuntimeException;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));
        config(['notifications.email_enabled' => true, 'mail.default' => 'array']);
        Queue::fake();
    }

    private function appointment(string $email = 'cliente@example.com', int $hours = 24): Appointment
    {
        $number = Barbershop::count() + 1;
        $shop = Barbershop::create([
            'name' => 'Unidade '.$number, 'slug' => 'unidade-'.$number,
            'timezone' => 'America/Sao_Paulo', 'address' => 'Rua Central',
            'phones' => ['+5511999999999'],
        ]);
        $barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $shop->id]);
        $shop->users()->attach($barber);
        $service = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Corte '.$number, 'description' => 'Corte completo',
            'duration' => 40, 'price' => 50, 'is_active' => true,
        ]);
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Cliente',
            'email' => $email, 'phone' => '+5511999999999',
        ]);
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id, 'client_id' => $client->id,
            'user_id' => $barber->id, 'service_id' => $service->id,
            'start_time' => now()->addHours($hours), 'end_time' => now()->addHours($hours)->addMinutes(40),
            'status' => 'scheduled',
        ]);
        $appointment->services()->attach($service);

        return $appointment;
    }

    private function requestEmail(string $email = 'cliente@example.com'): Notification
    {
        $this->from('/')->post('/cancelar-agendamento', ['email' => $email])
            ->assertRedirect('/')->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Caso haja algum agendamento para este endereço de e-mail, você receberá uma mensagem com as informações.');

        return Notification::where('template', 'booking_cancellation_request')->latest('id')->firstOrFail();
    }

    private function link(Notification $notification, int|string $target): string
    {
        return $notification->secret['links'][$target];
    }

    private function assertScheduled(Appointment ...$appointments): void
    {
        foreach ($appointments as $appointment) {
            $this->assertSame('scheduled', $appointment->fresh()->status);
        }
    }

    public function test_request_lists_all_matching_clients_and_units_without_tenant_leakage(): void
    {
        $first = $this->appointment('CLIENTE@example.com');
        $second = $this->appointment();
        $other = $this->appointment('outro@example.com');
        $cancelled = $this->appointment();
        $cancelled->update(['status' => 'cancelled']);
        $past = $this->appointment(hours: -1);
        $deleted = $this->appointment();
        $deleted->delete();
        $this->actingAs($other->barber);

        $notification = $this->requestEmail('  Cliente@example.com  ');
        $this->assertSame('cliente@example.com', $notification->recipient);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($notification->payload['appointments'], 'id'),
        );
        $this->assertCount(3, $notification->secret['links']);
        $this->get($this->link($notification, 'all'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('BookingCancellation')
                ->where('canCancel', true)->has('appointments', 2));
        $this->assertScheduled($first, $second, $other, $past);
    }

    public function test_unknown_email_and_disabled_channel_redirect_without_disclosing_reservations(): void
    {
        $this->post('/cancelar-agendamento', ['email' => 'unknown@example.com'])
            ->assertRedirect('/')->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Caso haja algum agendamento para este endereço de e-mail, você receberá uma mensagem com as informações.');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('booking_cancellation_requests', 0);

        $this->appointment();
        config(['notifications.email_enabled' => false]);
        $this->post('/cancelar-agendamento', ['email' => 'cliente@example.com'])
            ->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('booking_cancellation_requests', 0);
    }

    public function test_request_validation_and_email_throttle_are_inline_errors(): void
    {
        $this->post('/cancelar-agendamento', ['email' => ['invalid']])->assertSessionHasErrors('email');
        $this->post('/cancelar-agendamento', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post('/cancelar-agendamento', ['email' => 'unknown@example.com'])->assertSessionHasNoErrors();
        $this->post('/cancelar-agendamento', ['email' => 'UNKNOWN@example.com'])
            ->assertRedirect('/')->assertSessionHasErrors('email')->assertHeader('Retry-After');
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_request_is_also_throttled_by_ip_across_distinct_addresses(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/cancelar-agendamento', ['email' => "unknown$i@example.com"])->assertSessionHasNoErrors();
        }
        $this->post('/cancelar-agendamento', ['email' => 'sixth@example.com'])->assertSessionHasErrors('email');
    }

    public function test_email_and_tokens_are_encrypted_and_rendered_in_both_formats(): void
    {
        $first = $this->appointment();
        $this->appointment();
        $notification = $this->requestEmail();
        $mail = new NotificationEmail($notification);
        $html = $mail->render();
        $text = view($mail->textView(), $mail->data())->render();
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Corte 1', $body);
            $this->assertStringContainsString('12/11/2026 09:00', $body);
            $this->assertStringContainsString('America/Sao_Paulo', $body);
            $this->assertStringContainsString('Cancelar todos', $body);
            $this->assertStringContainsString($this->link($notification, $first->id), $body);
        }
        $url = $this->link($notification, $first->id);
        $token = explode('/', parse_url($url, PHP_URL_PATH))[2];
        $grant = BookingCancellationRequest::firstOrFail();
        $this->assertSame(hash('sha256', $token), $grant->token_hash);
        $this->assertSame(now()->addHour()->getTimestamp(), $grant->expires_at->getTimestamp());
        $this->assertStringNotContainsString($token, json_encode($notification->payload));
        $this->assertStringNotContainsString($token, $notification->getRawOriginal('secret'));
        $this->assertStringNotContainsString('cliente@example.com', $grant->getRawOriginal('email'));
        $this->assertArrayNotHasKey('secret', $notification->toArray());
        app(EmailDelivery::class)->send($notification->id);
        $notification->refresh();
        $this->assertSame('simulated', $notification->status);
        $this->assertStringContainsString('Cancelar todos', $notification->message['text']);
        $this->assertStringNotContainsString($token, $notification->getRawOriginal('message'));
    }

    public function test_ineligible_reservations_are_listed_without_actions_and_prevent_bulk_link(): void
    {
        $first = $this->appointment();
        $late = $this->appointment(hours: 11);
        $notification = $this->requestEmail();
        $this->assertCount(2, $notification->payload['appointments']);
        $this->assertArrayHasKey($first->id, $notification->secret['links']);
        $this->assertArrayNotHasKey($late->id, $notification->secret['links']);
        $this->assertArrayNotHasKey('all', $notification->secret['links']);
        $this->assertStringContainsString('prazo', $notification->payload['appointments'][0]['reason']);
        $all = preg_replace('/\d+$/', 'all', $this->link($notification, $first->id));
        $this->get($all)->assertInertia(fn (Assert $page) => $page->where('canCancel', false)->has('appointments', 0));
        $this->post($all)->assertSessionHasErrors('cancellation');
        $this->assertScheduled($first, $late);
    }

    public function test_single_reservation_does_not_get_a_bulk_action_and_get_never_cancels(): void
    {
        $appointment = $this->appointment();
        $notification = $this->requestEmail();
        $this->assertCount(1, $notification->secret['links']);
        $url = $this->link($notification, $appointment->id);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', true));
        $this->get($url)->assertOk();
        $this->assertScheduled($appointment);
        $this->assertDatabaseMissing('notifications', ['template' => 'booking_cancellation']);
    }

    public function test_confirm_cancels_once_and_preserves_a_persistent_success_page(): void
    {
        $appointment = $this->appointment();
        $admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $appointment->barbershop_id]);
        $appointment->barbershop->users()->attach($admin);
        app(BookingNotifications::class)->confirmation(
            $appointment, $appointment->client, $appointment->barbershop, $appointment->barber,
            $appointment->services, CarbonImmutable::instance($appointment->start_time),
        );
        $notification = $this->requestEmail();
        $url = $this->link($notification, $appointment->id);
        $this->from($url)->post($url)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame(3, Notification::where('template', 'booking_cancellation')->where('type', 'email')->count());
        $this->assertDatabaseHas('notifications', ['appointment_id' => $appointment->id, 'type' => 'whatsapp', 'template' => 'booking_cancellation']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('canCancel', false)->where('message', 'Cancelamento confirmado. Os horários não estão mais reservados.'));
        $this->from($url)->post($url)->assertSessionHasErrors('cancellation');
        $this->assertSame(3, Notification::where('template', 'booking_cancellation')->where('type', 'email')->count());
    }

    public function test_bulk_cancellation_only_cancels_the_snapshot_not_later_reservations(): void
    {
        $first = $this->appointment();
        $second = $this->appointment();
        $notification = $this->requestEmail();
        $later = $this->appointment();
        $url = $this->link($notification, 'all');
        $this->post($url)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertScheduled($later);
        $this->assertFalse($first->fresh()->email_notifications_enabled);
        $this->assertSame(4, Notification::where('template', 'booking_cancellation')->count());
        foreach ([$first, $second] as $appointment) {
            $this->assertDatabaseHas('notifications', [
                'appointment_id' => $appointment->id, 'recipient' => $appointment->barber->email,
                'audience' => 'barber', 'template' => 'booking_cancellation',
            ]);
        }
    }

    public function test_client_cancellation_notifies_only_responsible_staff_even_for_legacy_bookings(): void
    {
        $appointment = $this->appointment();
        $shop = $appointment->barbershop;
        $admin = User::factory()->create(['role' => 'admin']);
        $secondAdmin = User::factory()->create(['role' => 'admin']);
        $unlinkedAdmin = User::factory()->create(['role' => 'admin']);
        $colleague = User::factory()->create(['role' => 'barber']);
        $shop->users()->attach([$admin->id, $secondAdmin->id, $colleague->id]);
        $notification = $this->requestEmail();
        $url = $this->link($notification, $appointment->id);
        $this->post($url)->assertSessionHasNoErrors();
        $messages = Notification::where('template', 'booking_cancellation')->get();
        $this->assertEqualsCanonicalizing(
            [$appointment->client->email, $appointment->barber->email, $admin->email, $secondAdmin->email],
            $messages->pluck('recipient')->all(),
        );
        $this->assertNotContains($unlinkedAdmin->email, $messages->pluck('recipient'));
        $this->assertNotContains($colleague->email, $messages->pluck('recipient'));
        foreach ($messages as $message) {
            $this->assertSame($shop->id, $message->barbershop_id);
            $this->assertTrue($message->payload['cancelled_by_client']);
            $this->assertArrayNotHasKey('price', $message->payload);
            $this->assertNull(app(EmailDelivery::class)->ineligible($message));
            $mail = new NotificationEmail($message);
            $this->assertStringContainsString('O cliente confirmou o cancelamento', $mail->render());
            $this->assertStringContainsString('O cliente confirmou o cancelamento', view($mail->textView(), $mail->data())->render());
            app(EmailDelivery::class)->send($message->id);
            $this->assertSame('simulated', $message->fresh()->status);
        }
        $this->assertFalse($appointment->fresh()->email_notifications_enabled);
        $this->post($url)->assertSessionHasErrors('cancellation');
        $this->assertSame(4, Notification::where('template', 'booking_cancellation')->count());
    }

    public function test_client_cancellation_still_respects_the_global_email_switch(): void
    {
        $appointment = $this->appointment();
        $notification = $this->requestEmail();
        config(['notifications.email_enabled' => false]);
        $this->post($this->link($notification, $appointment->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['template' => 'booking_cancellation']);
    }

    public function test_client_cancellation_deduplicates_staff_addresses(): void
    {
        $appointment = $this->appointment();
        $appointment->barber->update(['email' => 'CLIENTE@example.com']);
        $notification = $this->requestEmail();
        $this->post($this->link($notification, $appointment->id))->assertSessionHasNoErrors();
        $messages = Notification::where('template', 'booking_cancellation')->get();
        $this->assertCount(1, $messages);
        $this->assertSame('cliente@example.com', $messages->first()->recipient);
    }

    public function test_bulk_is_atomic_if_any_reservation_was_cancelled_or_rescheduled(): void
    {
        $first = $this->appointment();
        $second = $this->appointment();
        $notification = $this->requestEmail();
        $second->update(['start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addMinutes(40)]);
        $this->from('/')->post($this->link($notification, 'all'))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($first, $second);
        $second->update(['status' => 'cancelled']);
        $this->post($this->link($notification, 'all'))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($first);
    }

    public function test_bulk_is_atomic_when_a_deadline_passes_or_policy_changes(): void
    {
        $first = $this->appointment(hours: 24);
        $second = $this->appointment(hours: 12);
        $notification = $this->requestEmail();
        $this->travel(1)->seconds();
        $this->post($this->link($notification, 'all'))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($first, $second);
        $this->travelBack();
        $second->barbershop->update(['cancellation_notice_hours' => 48]);
        $this->post($this->link($notification, 'all'))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($first, $second);
    }

    public function test_exact_notice_boundary_is_allowed_but_one_second_after_is_not(): void
    {
        $appointment = $this->appointment(hours: 12);
        $notification = $this->requestEmail();
        $this->get($this->link($notification, $appointment->id))
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', true));
        $this->travel(1)->seconds();
        $this->get($this->link($notification, $appointment->id))
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', false));
        $this->post($this->link($notification, $appointment->id))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($appointment);
    }

    public function test_before_notice_boundary_and_zero_policy_allow_cancellation_but_not_started_reservations(): void
    {
        $appointment = $this->appointment(hours: 12);
        $appointment->update(['start_time' => $appointment->start_time->addSecond()]);
        $notification = $this->requestEmail();
        $this->post($this->link($notification, $appointment->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $appointment->fresh()->status);

        $zero = $this->appointment(hours: 1);
        $zero->barbershop->update(['cancellation_notice_hours' => 0]);
        app(BookingCancellations::class)->request('cliente@example.com');
        $notification = Notification::where('template', 'booking_cancellation_request')->latest('id')->firstOrFail();
        $this->travel(59)->minutes();
        $this->get($this->link($notification, $zero->id))->assertInertia(fn (Assert $page) => $page->where('canCancel', true));
        // Keep the link valid while reaching the exact start of the appointment.
        BookingCancellationRequest::latest('id')->firstOrFail()->update(['expires_at' => now()->addHour()]);
        $this->travel(1)->minutes();
        $this->post($this->link($notification, $zero->id))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($zero);
    }

    public function test_invalid_expired_and_other_reservation_links_cannot_cancel(): void
    {
        $appointment = $this->appointment();
        $other = $this->appointment('other@example.com');
        $notification = $this->requestEmail();
        $url = $this->link($notification, $appointment->id);
        $otherUrl = preg_replace('/\d+$/', (string) $other->id, $url);
        $invalid = '/cancelar-agendamento/'.str_repeat('0', 64).'/'.$appointment->id;
        foreach ([$otherUrl, $invalid] as $link) {
            $this->get($link)->assertInertia(fn (Assert $page) => $page->where('canCancel', false)->has('appointments', 0));
            $this->post($link)->assertSessionHasErrors('cancellation');
        }
        $this->travel(60)->minutes();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canCancel', false)->has('appointments', 0));
        $this->post($url)->assertSessionHasErrors('cancellation');
        $this->assertScheduled($appointment, $other);
        $this->assertSame('expired', app(EmailDelivery::class)->ineligible($notification->fresh()));
    }

    public function test_changed_client_email_hides_details_and_invalidates_the_link(): void
    {
        $appointment = $this->appointment();
        $notification = $this->requestEmail();
        $appointment->client->update(['email' => 'new@example.com']);
        $url = $this->link($notification, $appointment->id);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canCancel', false)->has('appointments', 0));
        $this->post($url)->assertSessionHasErrors('cancellation');
        $this->assertScheduled($appointment);
    }

    public function test_deleted_completed_and_reassigned_reservations_are_not_cancelled(): void
    {
        $appointment = $this->appointment();
        $notification = $this->requestEmail();
        $url = $this->link($notification, $appointment->id);
        $appointment->update(['status' => 'completed']);
        $this->post($url)->assertSessionHasErrors('cancellation');
        $appointment->update(['status' => 'scheduled', 'user_id' => User::factory()->create()->id]);
        $this->post($url)->assertSessionHasErrors('cancellation');
        $this->assertScheduled($appointment);
        $appointment->delete();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canCancel', false)->has('appointments', 0));
        $this->post($url)->assertSessionHasErrors('cancellation');
    }

    public function test_checkout_prevents_cancellation_even_if_status_is_scheduled(): void
    {
        $appointment = $this->appointment();
        $notification = $this->requestEmail();
        $appointment->checkout()->create([
            'created_by' => $appointment->user_id, 'services' => [], 'products' => [],
            'services_total_cents' => 0, 'products_total_cents' => 0, 'discount_cents' => 0,
            'total_cents' => 0, 'payment_method' => 'cash',
        ]);
        $this->post($this->link($notification, $appointment->id))->assertSessionHasErrors('cancellation');
        $this->assertScheduled($appointment);
    }

    public function test_legacy_combined_appointment_cancels_as_a_whole(): void
    {
        $appointment = $this->appointment();
        $secondService = Service::create([
            'barbershop_id' => $appointment->barbershop_id, 'name' => 'Barba', 'description' => 'Barba completa',
            'duration' => 20, 'price' => 30, 'is_active' => true,
        ]);
        $appointment->services()->attach($secondService);
        $notification = $this->requestEmail();
        $this->assertSame('Corte 1 + Barba', $notification->payload['appointments'][0]['service']);
        $this->post($this->link($notification, $appointment->id))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame(2, $appointment->services()->count());
    }

    public function test_notification_filter_supports_requests_without_exposing_secrets_and_expired_retry_is_blocked(): void
    {
        $this->appointment();
        $notification = $this->requestEmail();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/notifications?template=booking_cancellation_request')
            ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1)
                ->missing('notifications.data.0.secret')->missing('notifications.data.0.payload'));
        $notification->update(['status' => 'failed', 'failure_code' => 'smtp_temporary_rejection']);
        $this->assertTrue(app(RetryEmailNotification::class)->allowed($notification));
        $this->travel(61)->minutes();
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($notification));
        app(EmailDelivery::class)->send($notification->id);
        $this->assertNull($notification->fresh()->message);
    }

    public function test_confirmation_routes_use_web_csrf_middleware(): void
    {
        $this->get('/')->assertOk();
        $route = app('router')->getRoutes()->getByName('booking.cancellation.confirm');
        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertNotEmpty(array_filter($middleware, fn ($class) => is_a($class, PreventRequestForgery::class, true)), implode(', ', $middleware));
    }

    public function test_confirmation_rejects_missing_csrf_token_when_verification_is_active(): void
    {
        $appointment = $this->appointment();
        $notification = $this->requestEmail();
        $url = $this->link($notification, $appointment->id);
        $middleware = new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $middleware);
        $this->post($url)->assertStatus(419);
        $this->assertScheduled($appointment);
        $this->withSession(['_token' => 'valid-csrf-token'])->post($url, ['_token' => 'valid-csrf-token'])
            ->assertSessionHasNoErrors()->assertRedirect($url);
        $this->assertSame('cancelled', $appointment->fresh()->status);
    }

    public function test_outbox_failure_rolls_back_the_request_and_is_not_reported_as_success(): void
    {
        $this->appointment();
        $this->mock(EmailOutbox::class)->shouldReceive('create')->once()->andThrow(new RuntimeException('Outbox unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/cancelar-agendamento', ['email' => 'cliente@example.com']);
            $this->fail('The outbox failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Outbox unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('booking_cancellation_requests', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_failure_rolls_back_the_entire_cancellation(): void
    {
        $first = $this->appointment();
        $second = $this->appointment();
        $notification = $this->requestEmail();
        $this->mock(BookingNotifications::class)->shouldReceive('changed')->once()->andThrow(new RuntimeException('Notification unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->post($this->link($notification, 'all'));
            $this->fail('The cancellation failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification unavailable', $exception->getMessage());
        }
        $this->assertScheduled($first, $second);
        $this->assertNull(BookingCancellationRequest::firstOrFail()->cancelled_ids);
    }

    public function test_public_registration_remains_disabled(): void
    {
        if (Features::enabled(Features::registration())) {
            $this->markTestSkipped('Registration is explicitly enabled in this environment.');
        }
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
