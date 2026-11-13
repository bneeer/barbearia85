<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_includes_outbox_indexes_and_foreign_keys(): void
    {
        foreach ([
            'notifications_appointment_id_index',
            'notifications_event_id_index',
            'notifications_deduplication_key_unique',
            'notifications_provider_id_unique',
            'notifications_type_status_next_attempt_at_index',
        ] as $index) {
            $this->assertTrue(Schema::hasIndex('notifications', $index));
        }
        $this->assertFalse(Schema::hasIndex('notifications', 'notifications_appointment_id_type_unique'));
        $this->assertCount(4, Schema::getForeignKeys('notifications'));
        $this->assertCount(2, Schema::getForeignKeys('notification_attempts'));
        $this->assertTrue(Schema::hasIndex('notification_webhook_events', 'notification_webhook_events_event_id_unique'));
        $this->assertTrue(Schema::hasIndex('notification_webhook_events', 'notification_webhook_events_provider_id_index'));
        $this->assertTrue(Schema::hasIndex('appointments', 'appointments_user_id_start_time_end_time_index'));
    }

    private function message(array $attributes = []): Notification
    {
        return Notification::create([
            'type' => 'email', 'template' => 'verify_email',
            'status' => 'processing', 'recipient' => 'cliente@example.com',
            ...$attributes,
        ]);
    }

    public function test_outbox_accepts_account_messages_without_client_and_preserves_delivery_history(): void
    {
        $user = User::factory()->create();
        $message = $this->message(['user_id' => $user->id]);
        $this->assertNull($message->client_id);
        $this->assertSame(0, $message->fresh()->attempts);
        $message->attemptsHistory()->create(['number' => 1, 'created_at' => now(new \DateTimeZone('America/Sao_Paulo'))]);
        $user->delete();
        $this->assertNull($message->fresh()->user_id);
        $this->assertDatabaseCount('notification_attempts', 1);
        $message->delete();
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    public function test_multiple_messages_per_appointment_are_allowed_and_email_starts_disabled(): void
    {
        $shop = Barbershop::create(['name' => 'Centro', 'slug' => 'centro']);
        $user = User::factory()->create(['barbershop_id' => $shop->id]);
        $client = Client::create([
            'barbershop_id' => $shop->id, 'name' => 'Cliente',
            'email' => 'cliente@example.com', 'phone' => '+5511999999999',
        ]);
        $service = Service::create([
            'barbershop_id' => $shop->id, 'name' => 'Corte',
            'description' => 'Corte', 'duration' => 40, 'price' => 50,
        ]);
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id, 'client_id' => $client->id, 'user_id' => $user->id,
            'service_id' => $service->id, 'start_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDay(), 'end_time' => now(new \DateTimeZone('America/Sao_Paulo'))->addDay()->addMinutes(40),
        ]);
        $this->assertFalse($appointment->fresh()->email_notifications_enabled);
        foreach (['pending', 'sent', 'skipped', 'simulated', 'failed', 'processing'] as $status) {
            $this->message(['appointment_id' => $appointment->id, 'status' => $status]);
        }
        $this->assertDatabaseCount('notifications', 6);
    }

    public function test_deduplication_keys_remain_unique(): void
    {
        $this->message(['deduplication_key' => 'same-event-recipient']);
        $this->expectException(QueryException::class);
        $this->message(['deduplication_key' => 'same-event-recipient']);
    }

    public function test_provider_ids_remain_unique(): void
    {
        $this->message(['provider_id' => 'smtp-message-id']);
        $this->expectException(QueryException::class);
        $this->message(['provider_id' => 'smtp-message-id']);
    }

    public function test_webhook_event_ids_remain_unique(): void
    {
        $event = [
            'event_id' => 'event-1', 'provider_id' => 'provider-1',
            'type' => 'email.delivered', 'occurred_at' => now(new \DateTimeZone('America/Sao_Paulo')), 'created_at' => now(new \DateTimeZone('America/Sao_Paulo')),
        ];
        DB::table('notification_webhook_events')->insert($event);
        $this->expectException(QueryException::class);
        DB::table('notification_webhook_events')->insert($event);
    }
}
