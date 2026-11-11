<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function legacySchema(): Appointment
    {
        $shop = Barbershop::create(['name' => 'Centro', 'slug' => 'centro', 'timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->create(['barbershop_id' => $shop->id]);
        $client = Client::create(['barbershop_id' => $shop->id, 'name' => 'Cliente', 'email' => 'test@example.com', 'phone' => '+5511999999999']);
        $service = Service::create(['barbershop_id' => $shop->id, 'name' => 'Corte', 'description' => 'Corte', 'duration' => 40, 'price' => 50]);
        $appointment = Appointment::create([
            'barbershop_id' => $shop->id, 'client_id' => $client->id, 'user_id' => $user->id,
            'service_id' => $service->id, 'start_time' => now()->addDay(), 'end_time' => now()->addDay()->addMinutes(40),
        ]);
        Schema::drop('notification_attempts');
        Schema::drop('notification_webhook_events');
        Schema::drop('notifications');
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('email_notifications_enabled'));
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['whatsapp', 'email']);
            $table->string('template');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['appointment_id', 'type']);
        });
        foreach ([['email', 'pending', $appointment->id], ['whatsapp', 'pending', $appointment->id], ['email', 'sent', null]] as [$type, $status, $appointmentId]) {
            DB::table('notifications')->insert([
                'client_id' => $client->id, 'appointment_id' => $appointmentId, 'type' => $type,
                'template' => 'booking_confirmation', 'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $appointment;
    }

    private function migrateNotifications(): void
    {
        $migration = require database_path('migrations/2026_11_10_000000_enable_email_notifications.php');
        $migration->up();
    }

    public function test_upgrade_preserves_sent_and_whatsapp_history_but_excludes_all_old_bookings(): void
    {
        $appointment = $this->legacySchema();
        $this->migrateNotifications();
        $this->assertFalse($appointment->fresh()->email_notifications_enabled);
        $this->assertDatabaseHas('notifications', ['type' => 'email', 'status' => 'skipped', 'failure_code' => 'activation_cutoff']);
        $this->assertDatabaseHas('notifications', ['type' => 'whatsapp', 'status' => 'pending']);
        $this->assertDatabaseHas('notifications', ['type' => 'email', 'status' => 'sent']);
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_retry_after_appointment_column_was_added_preserves_its_values(): void
    {
        $appointment = $this->legacySchema();
        Schema::table('appointments', fn (Blueprint $table) => $table->boolean('email_notifications_enabled')->default(false));
        $this->migrateNotifications();

        $this->assertFalse($appointment->fresh()->email_notifications_enabled);
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_appointment_id_index'));
        $this->assertFalse(Schema::hasIndex('notifications', 'notifications_appointment_id_type_unique'));
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_retry_recovers_existing_columns_and_tables_with_missing_constraints(): void
    {
        $this->legacySchema();
        Schema::table('appointments', fn (Blueprint $table) => $table->boolean('email_notifications_enabled')->default(false));
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['appointment_id', 'type']);
            $table->foreignId('user_id')->nullable();
            $table->uuid('event_id')->nullable();
            $table->string('deduplication_key')->nullable();
        });
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id');
            $table->unsignedSmallInteger('number');
            $table->string('result')->default('processing');
            $table->string('code')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('finished_at')->nullable();
        });
        Schema::create('notification_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id');
            $table->string('provider_id');
            $table->string('type');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
        });

        $this->migrateNotifications();

        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_event_id_index'));
        $this->assertTrue(Schema::hasIndex('notifications', 'notifications_deduplication_key_unique'));
        $this->assertTrue(Schema::hasIndex('notification_webhook_events', 'notification_webhook_events_event_id_unique'));
        $this->assertCount(2, Schema::getForeignKeys('notification_attempts'));
        $this->assertCount(4, Schema::getForeignKeys('notifications'));
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_repeating_the_migration_preserves_new_eligible_messages_and_delivery_history(): void
    {
        $appointment = $this->legacySchema();
        $this->migrateNotifications();
        DB::table('appointments')->where('id', $appointment->id)->update(['email_notifications_enabled' => true]);
        DB::table('notifications')->insert([
            'client_id' => null, 'type' => 'email', 'template' => 'verify_email',
            'status' => 'pending', 'deduplication_key' => 'new-message', 'recipient' => 'new@example.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notification_attempts')->insert([
            'notification_id' => DB::table('notifications')->where('deduplication_key', 'new-message')->value('id'),
            'number' => 1, 'result' => 'retry_scheduled', 'created_at' => now(),
        ]);
        DB::table('notification_webhook_events')->insert([
            'event_id' => 'event-1', 'provider_id' => 'provider-1', 'type' => 'email.delivered',
            'occurred_at' => now(), 'created_at' => now(),
        ]);

        $this->migrateNotifications();
        $this->migrateNotifications();

        $this->assertTrue($appointment->fresh()->email_notifications_enabled);
        $this->assertDatabaseHas('notifications', ['deduplication_key' => 'new-message', 'status' => 'pending']);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('notification_attempts', 1);
        $this->assertDatabaseCount('notification_webhook_events', 1);
        $this->assertCount(2, Schema::getForeignKeys('notification_attempts'));
        $this->assertCount(4, Schema::getForeignKeys('notifications'));
    }
}
