<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Jobs\SendEmailNotification;
use App\Mail\NotificationEmail;
use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingNotifications;
use App\Services\EmailDelivery;
use App\Services\EmailDeliveryFailure;
use App\Services\EmailOutbox;
use App\Services\RetryEmailNotification;
use App\Services\SmtpGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Barbershop $shop;

    private User $barber;

    private User $admin;

    private Service $service;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.email_enabled' => true, 'mail.default' => 'smtp']);
        Queue::fake();
        $this->shop = Barbershop::create([
            'name' => 'Centro', 'slug' => 'centro', 'timezone' => 'America/Sao_Paulo',
            'address' => 'Rua Central, 85', 'is_open' => true, 'phones' => ['+5511999999999'],
            'opening_hours' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['open' => '09:00', 'close' => '20:00', 'closed' => false],
            ),
        ]);
        $this->barber = User::factory()->create(['role' => 'barber', 'barbershop_id' => $this->shop->id]);
        $this->admin = User::factory()->create(['role' => 'admin', 'barbershop_id' => $this->shop->id]);
        $this->shop->users()->attach([$this->barber->id, $this->admin->id]);
        $this->service = Service::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Corte', 'description' => 'Corte',
            'duration' => 40, 'price' => 85, 'is_active' => true,
        ]);
        $this->barber->services()->attach($this->service);
        $this->client = Client::create([
            'barbershop_id' => $this->shop->id, 'name' => 'Cliente',
            'email' => 'cliente@example.com', 'phone' => '+5511999999999',
        ]);
    }

    private function appointment(): Appointment
    {
        $appointment = Appointment::create([
            'barbershop_id' => $this->shop->id, 'client_id' => $this->client->id,
            'user_id' => $this->barber->id, 'service_id' => $this->service->id,
            'start_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDays(2), 'end_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDays(2)->addMinutes(40),
            'status' => 'scheduled',
        ]);
        $appointment->services()->attach($this->service);

        return $appointment;
    }

    private function confirmation(?Appointment $appointment = null): Notification
    {
        $appointment ??= $this->appointment();
        app(BookingNotifications::class)->confirmation(
            $appointment, $this->client, $this->shop, $this->barber,
            collect([$this->service]), CarbonImmutable::instance($appointment->start_time)->setTimezone($this->shop->timezone),
        );

        return Notification::where('appointment_id', $appointment->id)->where('type', 'email')->firstOrFail();
    }

    private function gateway(?EmailDeliveryFailure $failure = null): void
    {
        $mock = $this->mock(SmtpGateway::class);
        $expectation = $mock->shouldReceive('send')->once();
        $failure ? $expectation->andThrow($failure) : $expectation->andReturn('smtp-message-id');
    }

    public function test_confirmation_notifies_only_the_client_responsible_barber_and_linked_admin(): void
    {
        User::factory()->create(['role' => 'admin']);
        $colleague = User::factory()->create(['role' => 'barber']);
        $this->shop->users()->attach($colleague);
        $record = $this->confirmation();
        $this->assertSame(3, Notification::where('type', 'email')->count());
        $this->assertEqualsCanonicalizing(
            [$this->client->email, $this->barber->email, $this->admin->email],
            Notification::where('type', 'email')->pluck('recipient')->all(),
        );
        $this->assertTrue($record->appointment->email_notifications_enabled);
        $this->assertSame(1, Notification::where('type', 'whatsapp')->count());
        foreach (Notification::whereIn('audience', ['barber', 'admin'])->get() as $team) {
            $this->assertArrayNotHasKey('price', $team->payload);
            $this->assertStringNotContainsString('R$', (new NotificationEmail($team))->render());
        }
    }

    public function test_duplicate_addresses_receive_one_message_per_event(): void
    {
        $this->barber->update(['email' => $this->client->email]);
        $this->confirmation();
        $this->assertSame(2, Notification::where('type', 'email')->count());
    }

    public function test_scheduler_queues_one_48_hour_reminder_with_a_discreet_safe_cancellation_link(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'UTC'));
        $appointment = $this->appointment();
        $appointment->update(['start_time' => now()->addHours(48), 'end_time' => now()->addHours(48)->addMinutes(40)]);
        $this->confirmation($appointment);
        $appointment->forceFill(['created_at' => $appointment->start_time->copy()->subHours(49)])->save();

        $recent = $this->appointment();
        $recent->update(['start_time' => now()->addHours(47), 'end_time' => now()->addHours(47)->addMinutes(40)]);
        $this->confirmation($recent);

        $this->artisan('notifications:reminders')->assertSuccessful();
        $reminder = Notification::where('template', 'booking_reminder')->firstOrFail();
        $this->assertSame($appointment->id, $reminder->appointment_id);
        $this->assertSame('client', $reminder->audience);
        $this->assertSame($this->client->email, $reminder->recipient);
        $this->assertSame('Corte', $reminder->payload['service']);
        $this->assertSame('Rua Central, 85', $reminder->payload['address']);

        $cancelUrl = $reminder->secret['cancellation_url'];
        $html = (new NotificationEmail($reminder))->render();
        $text = view('mail.notification-text', (new NotificationEmail($reminder))->data())->render();
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Lembrete do seu atendimento', $body);
            $this->assertStringContainsString('Corte', $body);
            $this->assertStringContainsString('Centro', $body);
            $this->assertStringContainsString('Rua Central, 85', $body);
            $this->assertStringContainsString($cancelUrl, $body);
        }
        $this->assertStringContainsString('Se precisar cancelar', $html);
        $this->assertStringNotContainsString('background:#c62828', $html);

        $this->get($cancelUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', true));
        $this->assertSame('scheduled', $appointment->fresh()->status);
        $this->post($cancelUrl)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertSame('skipped', $reminder->fresh()->status);

        $this->artisan('notifications:reminders')->assertSuccessful();
        $this->assertSame(1, Notification::where('template', 'booking_reminder')->count());
    }

    public function test_existing_and_disabled_bookings_stay_ineligible_without_explicit_backfill(): void
    {
        $old = $this->appointment();
        config(['notifications.email_enabled' => false]);
        $record = $this->confirmation();
        config(['notifications.email_enabled' => true]);
        foreach ([$old, $record->appointment] as $appointment) {
            $previous = $appointment->start_time->toIso8601String();
            $appointment->update(['status' => 'cancelled']);
            app(BookingNotifications::class)->changed($appointment, $this->shop, $previous);
        }
        $this->assertSame(1, Notification::where('type', 'email')->count());
        app(EmailDelivery::class)->send($record->id);
        $this->assertNull($record->fresh()->sent_at);
        $this->assertNull($record->fresh()->deduplication_key);
    }

    public function test_retroactive_command_is_read_only_by_default_even_with_email_disabled(): void
    {
        config(['notifications.email_enabled' => false]);
        $record = $this->confirmation();
        $before = DB::table('notifications')->get()->toJson();
        Queue::fake();
        $this->artisan('notifications:backfill')
            ->expectsOutput('Agendamentos elegíveis: 1.')->assertSuccessful();
        $this->assertSame($before, DB::table('notifications')->get()->toJson());
        $this->assertFalse($record->appointment->fresh()->email_notifications_enabled);
        Queue::assertNothingPushed();
        $this->artisan('notifications:backfill', ['--write' => true])->assertFailed();
        config(['notifications.email_enabled' => true, 'mail.default' => 'log']);
        $this->artisan('notifications:backfill', ['--write' => true])->assertFailed();
        $this->assertFalse($record->appointment->fresh()->email_notifications_enabled);
    }

    public function test_retroactive_confirmations_use_current_booking_data_and_do_not_duplicate_whatsapp_or_email(): void
    {
        config(['notifications.email_enabled' => false]);
        $legacy = $this->confirmation();
        $appointment = $legacy->appointment;
        $appointment->update(['start_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDays(3)]);
        $this->client->update(['email' => 'current@example.com']);
        config(['notifications.email_enabled' => true]);
        Queue::fake();
        $this->artisan('notifications:backfill', ['--write' => true])
            ->expectsOutput('Agendamentos enfileirados: 1.')->assertSuccessful();
        $this->assertTrue($appointment->fresh()->email_notifications_enabled);
        $this->assertSame('superseded', $legacy->fresh()->failure_code);
        $this->assertSame('skipped', $legacy->fresh()->status);
        $this->assertSame(1, Notification::where('type', 'whatsapp')->count());
        $emails = Notification::where('type', 'email')->whereNotNull('deduplication_key')->get();
        $this->assertCount(3, $emails);
        $this->assertEqualsCanonicalizing(
            ['current@example.com', $this->barber->email, $this->admin->email],
            $emails->pluck('recipient')->all(),
        );
        $this->assertSame(1, $emails->pluck('event_id')->unique()->count());
        $this->assertSame(
            $appointment->start_time->setTimezone($this->shop->timezone)->toIso8601String(),
            $emails->first()->payload['starts_at'],
        );
        Queue::assertPushed(SendEmailNotification::class, 3);
        $this->artisan('notifications:backfill', ['--write' => true])
            ->expectsOutput('Agendamentos enfileirados: 0.')->assertSuccessful();
        $this->assertFalse(app(BookingNotifications::class)->retroactiveConfirmation($appointment->id));
        $this->assertSame(4, Notification::where('type', 'email')->count());
        $this->gateway();
        app(EmailDelivery::class)->send($emails->first()->id);
        $this->assertSame('sent', $emails->first()->fresh()->status);
        $appointment->refresh()->update(['status' => 'cancelled']);
        app(BookingNotifications::class)->changed($appointment, $this->shop, $appointment->start_time->toIso8601String());
        $this->assertSame(3, Notification::where('template', 'booking_cancellation')->count());
    }

    public function test_retroactive_selection_excludes_past_cancelled_completed_deleted_and_already_enabled_bookings(): void
    {
        $this->confirmation();
        $this->appointment()->update(['start_time' => now(new \DateTimeZone('America/Sao_Paulo'))->subSecond()]);
        $this->appointment()->update(['start_time' => now(new \DateTimeZone('America/Sao_Paulo'))]);
        $this->appointment()->update(['status' => 'cancelled']);
        $this->appointment()->update(['status' => 'completed']);
        $this->appointment()->delete();
        $eligible = $this->appointment();
        $this->artisan('notifications:backfill', ['--write' => true])
            ->expectsOutput('Agendamentos enfileirados: 1.')->assertSuccessful();
        $this->assertTrue($eligible->fresh()->email_notifications_enabled);
        $this->assertSame(6, Notification::where('type', 'email')->count());
    }

    public function test_retroactive_selection_preserves_sent_and_uncertain_history_even_without_modern_keys(): void
    {
        foreach ([
            ['status' => 'sent'],
            ['status' => 'processing'],
            ['status' => 'failed'],
            ['status' => 'pending', 'attempts' => 1],
            ['status' => 'pending', 'failure_code' => 'smtp_result_unknown'],
            ['status' => 'pending', 'provider_id' => 'legacy-id'],
            ['status' => 'pending', 'sent_at' => now(new \DateTimeZone('America/Sao_Paulo'))],
            ['status' => 'pending', 'first_attempt_at' => now(new \DateTimeZone('America/Sao_Paulo'))],
        ] as $attributes) {
            $appointment = $this->appointment();
            Notification::create([
                'appointment_id' => $appointment->id, 'client_id' => $this->client->id,
                'type' => 'email', 'template' => 'booking_confirmation', 'payload' => [], ...$attributes,
            ]);
        }
        $modern = $this->confirmation();
        DB::table('appointments')->where('id', $modern->appointment_id)->update(['email_notifications_enabled' => false]);
        $appointment = $this->appointment();
        $history = Notification::create([
            'appointment_id' => $appointment->id, 'client_id' => $this->client->id,
            'type' => 'email', 'template' => 'booking_confirmation', 'status' => 'pending', 'payload' => [],
        ]);
        $history->attemptsHistory()->create(['number' => 1, 'result' => 'interrupted', 'created_at' => now(new \DateTimeZone('America/Sao_Paulo'))]);
        $before = DB::table('notifications')->get()->toJson();
        $this->artisan('notifications:backfill', ['--write' => true])
            ->expectsOutput('Agendamentos enfileirados: 0.')->assertSuccessful();
        $this->assertSame($before, DB::table('notifications')->get()->toJson());
    }

    public function test_retroactive_legacy_skipped_confirmation_is_rebuilt_with_service_fallback_and_deduplicated_recipients(): void
    {
        $appointment = $this->appointment();
        $appointment->services()->detach();
        $this->barber->update(['email' => $this->client->email]);
        $legacy = Notification::create([
            'appointment_id' => $appointment->id, 'client_id' => $this->client->id,
            'type' => 'email', 'template' => 'booking_confirmation', 'status' => 'skipped',
            'failure_code' => 'activation_cutoff', 'payload' => ['starts_at' => 'obsolete'],
        ]);
        $this->artisan('notifications:backfill', ['--write' => true])->assertSuccessful();
        $emails = Notification::whereNotNull('deduplication_key')->get();
        $this->assertCount(2, $emails);
        $this->assertSame($this->service->name, $emails->first()->payload['service']);
        $this->assertSame('activation_cutoff', $legacy->fresh()->failure_code);
        $this->assertSame(0, Notification::where('type', 'whatsapp')->count());
    }

    public function test_new_public_batch_and_admin_bookings_use_the_shared_email_outbox(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'America/Sao_Paulo')->utc());
        $selection = [
            'barbershop_id' => $this->shop->id, 'name' => 'Cliente',
            'email' => 'novo@example.com', 'phone' => '+5511999999999',
        ];
        $item = ['service_id' => $this->service->id, 'barber_id' => $this->barber->id, 'date' => '2026-11-09', 'time' => '09:00'];
        $this->postJson('/agendar', [...$selection, ...$item])->assertCreated();
        $this->postJson('/agendar', [...$selection, 'items' => [[...$item, 'time' => '09:40']]])->assertCreated();
        $this->actingAs($this->admin)->postJson('/schedule/appointments', [...$selection, ...$item, 'time' => '10:20'])->assertCreated();
        $this->assertSame(9, Notification::where('type', 'email')->count());
        $this->assertSame(3, DB::table('appointments')->where('email_notifications_enabled', true)->count());
    }

    public function test_schedule_routes_emit_new_events_only_for_effective_changes_to_eligible_bookings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'America/Sao_Paulo')->utc());
        $response = $this->postJson('/agendar', [
            'barbershop_id' => $this->shop->id, 'name' => 'Cliente', 'email' => $this->client->email,
            'phone' => $this->client->phone, 'service_id' => $this->service->id,
            'barber_id' => $this->barber->id, 'date' => '2026-11-09', 'time' => '09:00',
        ])->assertCreated();
        $id = $response->json('id');
        $this->actingAs($this->admin);
        $version = fn () => $this->getJson('/schedule/events?'.http_build_query([
            'barbershop_id' => $this->shop->id, 'start' => '2026-11-09T00:00:00-03:00', 'end' => '2026-11-10T00:00:00-03:00',
        ]))->assertOk()->json('0.extendedProps.version');
        $update = [
            'barbershop_id' => $this->shop->id, 'status' => 'scheduled',
            'start' => '2026-11-09T10:20', 'end' => '2026-11-09T11:00',
        ];
        $this->patchJson('/schedule/appointments/'.$id, [...$update, 'version' => $version()])->assertOk();
        $this->assertSame(3, Notification::where('template', 'booking_rescheduled')->count());
        $this->assertSame('2026-11-09T10:20:00-03:00', Notification::where('template', 'booking_rescheduled')->first()->payload['starts_at']);
        $this->patchJson('/schedule/appointments/'.$id, [...$update, 'version' => $version()])->assertOk();
        $this->assertSame(3, Notification::where('template', 'booking_rescheduled')->count());
        $this->patchJson('/schedule/appointments/'.$id, [
            'barbershop_id' => $this->shop->id, 'status' => 'cancelled', 'version' => $version(),
        ])->assertOk();
        $this->assertSame(3, Notification::where('type', 'email')->where('template', 'booking_cancellation')->count());
        $this->assertSame(6, Notification::where('type', 'email')->where('status', 'skipped')->count());
        $this->assertSame('2026-11-09T09:00:00-03:00', Notification::where('type', 'email')
            ->where('template', 'booking_confirmation')->first()->payload['starts_at']);
    }

    public function test_rescheduling_and_cancellation_create_immutable_events_after_confirmation_was_sent(): void
    {
        $record = $this->confirmation();
        $record->update(['status' => 'sent', 'provider_id' => 'previous', 'sent_at' => now(new \DateTimeZone('America/Sao_Paulo'))]);
        $appointment = $record->appointment;
        $previous = $appointment->start_time->toIso8601String();
        $appointment->update(['start_time' => $appointment->start_time->addHour()]);
        app(BookingNotifications::class)->changed($appointment, $this->shop, $previous);
        $this->assertSame('booking_confirmation', $record->fresh()->template);
        $this->assertSame(3, Notification::where('template', 'booking_rescheduled')->count());
        $this->assertSame($previous, Notification::where('template', 'booking_rescheduled')->first()->payload['previous_starts_at']);
        $appointment->update(['status' => 'cancelled']);
        app(BookingNotifications::class)->changed($appointment, $this->shop, $previous);
        $this->assertSame(3, Notification::where('template', 'booking_cancellation')->count());
        $this->assertSame(3, Notification::where('template', 'booking_rescheduled')->where('status', 'skipped')->count());
    }

    public function test_success_is_accepted_not_delivered_and_repeated_jobs_do_not_send_again(): void
    {
        $record = $this->confirmation();
        $this->gateway();
        app(EmailDelivery::class)->send($record->id);
        app(EmailDelivery::class)->send($record->id);
        $record->refresh();
        $this->assertSame('sent', $record->status);
        $this->assertSame('smtp-message-id', $record->provider_id);
        $this->assertNull($record->delivery_status);
        $this->assertNotNull($record->sent_at);
        $this->assertSame(1, $record->attempts);
        $this->assertSame('sent', $record->attemptsHistory()->first()->result);
    }

    public function test_five_attempts_use_exact_backoff_and_preserve_message_and_trace_key(): void
    {
        $record = $this->confirmation();
        $messages = [];
        $this->mock(SmtpGateway::class)->shouldReceive('send')->times(5)
            ->andReturnUsing(function ($message, $key) use (&$messages) {
                $messages[] = [$message, $key];
                throw new EmailDeliveryFailure('smtp_temporary_rejection', true);
            });
        foreach ([60, 300, 900, 3600, null] as $index => $delay) {
            $before = now(new \DateTimeZone('America/Sao_Paulo'));
            app(EmailDelivery::class)->send($record->id);
            $record->refresh();
            $this->assertSame($index + 1, $record->attempts);
            if ($delay) {
                $this->assertEquals($before->addSeconds($delay)->getTimestamp(), $record->next_attempt_at->getTimestamp());
                app(EmailDelivery::class)->send($record->id);
                $this->assertSame($index + 1, $record->fresh()->attempts);
                $this->travel($delay)->seconds();
            }
        }
        $this->assertSame('failed', $record->status);
        $this->assertCount(5, $messages);
        foreach ($messages as $message) {
            $this->assertSame($messages[0], $message);
        }
        $this->assertSame(5, $record->attemptsHistory()->count());
    }

    public function test_permanent_smtp_failures_stop_immediately(): void
    {
        $record = $this->confirmation();
        $this->gateway(new EmailDeliveryFailure('smtp_rejected', false));
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertNull($record->fresh()->next_attempt_at);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($record->fresh()));
    }

    public function test_lease_prevents_parallel_claim_and_blocks_interrupted_attempt(): void
    {
        $record = $this->confirmation();
        $record->update(['status' => 'processing', 'attempts' => 1, 'locked_until' => now(new \DateTimeZone('America/Sao_Paulo'))->addMinutes(2)]);
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame(1, $record->fresh()->attempts);
        $record->update(['locked_until' => now(new \DateTimeZone('America/Sao_Paulo'))->subSecond()]);
        $this->mock(SmtpGateway::class)->shouldNotReceive('send');
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame(1, $record->fresh()->attempts);
        $this->assertSame('reconciliation_required', $record->fresh()->failure_code);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($record->fresh()));
    }

    public function test_scheduler_recovers_due_messages_but_never_whatsapp_or_legacy_rows(): void
    {
        $record = $this->confirmation();
        Queue::fake();
        Notification::whereKeyNot($record->id)->where('type', 'email')->update(['next_attempt_at' => now(new \DateTimeZone('America/Sao_Paulo'))->addHour()]);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        Queue::assertPushed(SendEmailNotification::class, 1);
        Queue::assertPushed(SendEmailNotification::class, fn ($job) => $job->notificationId === $record->id);
    }

    public function test_uncertain_legacy_pending_messages_never_switch_to_smtp(): void
    {
        $record = $this->confirmation();
        $record->update(['attempts' => 1, 'failure_code' => 'provider_result_unknown']);
        $this->mock(SmtpGateway::class)->shouldNotReceive('send');
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame('reconciliation_required', $record->fresh()->failure_code);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($record->fresh()));
    }

    public function test_interrupted_history_blocks_sending_even_if_status_was_changed_to_pending(): void
    {
        $record = $this->confirmation();
        $record->update(['attempts' => 1]);
        $record->attemptsHistory()->create(['number' => 1, 'result' => 'processing', 'created_at' => now(new \DateTimeZone('America/Sao_Paulo'))]);
        $this->mock(SmtpGateway::class)->shouldNotReceive('send');
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('reconciliation_required', $record->fresh()->failure_code);
        $this->assertSame('interrupted', $record->attemptsHistory()->first()->result);
    }

    public function test_safe_smtp_failures_can_be_retried_after_more_than_twenty_three_hours(): void
    {
        $record = $this->confirmation();
        $record->update([
            'status' => 'failed', 'failure_code' => 'smtp_temporary_rejection', 'attempts' => 5,
            'first_attempt_at' => now(new \DateTimeZone('America/Sao_Paulo'))->subDay(),
        ]);
        $this->assertTrue(app(RetryEmailNotification::class)->allowed($record->fresh()));
        app(RetryEmailNotification::class)->retry($record->id, $this->admin->id);
        $this->gateway();
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('sent', $record->fresh()->status);
    }

    public function test_expired_and_superseded_messages_never_send(): void
    {
        $record = $this->confirmation();
        $record->update(['expires_at' => now(new \DateTimeZone('America/Sao_Paulo'))->subSecond()]);
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('expired', $record->fresh()->failure_code);
        $admin = Notification::where('audience', 'admin')->firstOrFail();
        $admin->appointment->update(['status' => 'cancelled']);
        app(EmailDelivery::class)->send($admin->id);
        $this->assertSame('superseded', $admin->fresh()->failure_code);
    }

    public function test_log_mailer_is_simulation_never_real_delivery(): void
    {
        config(['mail.default' => 'log']);
        $record = $this->confirmation();
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('simulated', $record->fresh()->status);
        $this->assertNull($record->fresh()->sent_at);
        $this->assertNull($record->fresh()->provider_id);
    }

    public function test_account_notifications_preserve_framework_links_but_encrypt_secrets(): void
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $verify = Notification::where('template', 'verify_email')->firstOrFail();
        $this->assertStringContainsString('signature=', $verify->secret['url']);
        $this->assertStringNotContainsString('signature=', DB::table('notifications')->where('id', $verify->id)->value('secret'));
        $user->sendPasswordResetNotification('test-private-token');
        $reset = Notification::where('template', 'reset_password')->firstOrFail();
        $this->assertStringContainsString('test-private-token', $reset->secret['url']);
        $this->assertStringNotContainsString('test-private-token', $reset->toJson());
        $this->assertStringNotContainsString('test-private-token', DB::table('notifications')->where('id', $reset->id)->value('secret'));
        $user->update(['email' => 'changed@example.com']);
        app(EmailDelivery::class)->send($reset->id);
        $this->assertSame('account_changed', $reset->fresh()->failure_code);
    }

    public function test_verified_accounts_and_expired_links_are_not_sent(): void
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $record = Notification::where('template', 'verify_email')->firstOrFail();
        $user->markEmailAsVerified();
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('account_changed', $record->fresh()->failure_code);
        $user->sendPasswordResetNotification('private-token');
        $reset = Notification::where('template', 'reset_password')->firstOrFail();
        $this->travel(61)->minutes();
        app(EmailDelivery::class)->send($reset->id);
        $this->assertSame('expired', $reset->fresh()->failure_code);
    }

    public function test_password_change_notice_is_created_for_settings_and_recovery(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertRedirect();
        $this->assertSame(1, Notification::where('template', 'password_changed')->count());
        app(ResetUserPassword::class)->reset($user->fresh(), [
            'password' => 'another-password', 'password_confirmation' => 'another-password',
        ]);
        $this->assertSame(2, Notification::where('template', 'password_changed')->count());
        $this->assertSame(1, Notification::where('template', 'password_changed')->where('status', 'pending')->count());
    }

    public function test_all_templates_have_html_text_branding_and_correct_local_time(): void
    {
        $record = $this->confirmation();
        foreach (['booking_confirmation', 'booking_reminder', 'booking_rescheduled', 'booking_cancellation'] as $template) {
            $record->template = $template;
            $mail = new NotificationEmail($record);
            $this->assertStringContainsString('#d3b04c', $mail->render());
            $text = view('mail.notification-text', $mail->data())->render();
            $this->assertStringContainsString('BARBEARIA 85', $text);
            $this->assertStringContainsString('America/Sao_Paulo', $text);
            $this->assertStringContainsString($record->appointment->start_time->setTimezone($this->shop->timezone)->format('H:i'), $text);
        }
        foreach (['verify_email', 'reset_password', 'password_changed'] as $template) {
            app(EmailOutbox::class)->authentication($this->admin, $template, $template === 'password_changed' ? null : 'https://example.test/secure');
            $account = Notification::where('template', $template)->firstOrFail();
            $this->assertStringContainsString('BARBEARIA 85', (new NotificationEmail($account))->render());
        }
    }

    public function test_unknown_smtp_results_never_retry_automatically_or_manually(): void
    {
        $record = $this->confirmation();
        $this->gateway(new EmailDeliveryFailure('smtp_result_unknown', false));
        app(EmailDelivery::class)->send($record->id);
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame('smtp_result_unknown', $record->fresh()->failure_code);
        $this->assertNull($record->fresh()->next_attempt_at);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($record->fresh()));
        $this->actingAs($this->admin)->postJson('/notifications/'.$record->id.'/retry')->assertUnprocessable();
    }

    public function test_admin_monitoring_is_global_sanitized_and_retries_are_audited(): void
    {
        app(EmailOutbox::class)->authentication($this->admin, 'reset_password', 'https://example.test/private-token');
        $record = Notification::where('template', 'reset_password')->firstOrFail();
        $this->actingAs($this->barber)->get('/notifications')->assertForbidden();
        $this->getJson('/notifications/'.$record->id)->assertForbidden();
        $this->post('/notifications/'.$record->id.'/retry')->assertForbidden();
        $this->actingAs($this->admin)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->has('notifications.data', 1)->missing('notifications.data.0.secret'));
        $response = $this->getJson('/notifications/'.$record->id)->assertOk();
        $this->assertStringNotContainsString('private-token', $response->getContent());
        $this->assertArrayNotHasKey('payload', $response->json());
        $record->update(['status' => 'failed', 'failure_code' => 'smtp_temporary_rejection', 'attempts' => 5]);
        $this->post('/notifications/'.$record->id.'/retry')->assertRedirect();
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertDatabaseHas('notification_attempts', [
            'notification_id' => $record->id, 'result' => 'retry_requested', 'requested_by' => $this->admin->id,
        ]);
        $this->postJson('/notifications/'.$record->id.'/retry')->assertUnprocessable();
        $record->update(['status' => 'failed', 'failure_code' => 'provider_result_unknown']);
        $this->postJson('/notifications/'.$record->id.'/retry')->assertUnprocessable();
    }

    public function test_outbox_transaction_rollback_does_not_publish_or_retain_messages(): void
    {
        Queue::fake();
        DB::beginTransaction();
        app(EmailOutbox::class)->authentication($this->admin, 'verify_email', 'https://example.test/secret');
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_disabled_live_channel_does_not_send_and_final_interrupted_attempt_requires_reconciliation(): void
    {
        $record = $this->confirmation();
        config(['notifications.email_enabled' => false]);
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame(0, $record->fresh()->attempts);
        config(['notifications.email_enabled' => true]);
        $record->update(['status' => 'processing', 'attempts' => 5, 'locked_until' => now(new \DateTimeZone('America/Sao_Paulo'))->subSecond()]);
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('reconciliation_required', $record->fresh()->failure_code);
        $this->assertFalse(app(RetryEmailNotification::class)->allowed($record->fresh()));
    }

    public function test_unsupported_mailer_fails_explicitly(): void
    {
        $record = $this->confirmation();
        config(['mail.default' => 'sendmail']);
        app(EmailDelivery::class)->send($record->id);
        $this->assertSame('unsupported_mailer', $record->fresh()->failure_code);
    }
}
