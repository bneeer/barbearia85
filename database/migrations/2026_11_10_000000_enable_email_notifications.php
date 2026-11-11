<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('appointments', 'email_notifications_enabled')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->boolean('email_notifications_enabled')->default(false);
            });
        }

        // MySQL may use the old composite unique index to support this foreign key.
        $this->ensureIndex('notifications', 'notifications_appointment_id_index', ['appointment_id']);
        if (Schema::hasIndex('notifications', 'notifications_appointment_id_type_unique')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropUnique('notifications_appointment_id_type_unique');
            });
        }
        $existingColumns = array_column(Schema::getColumns('notifications'), null, 'name');
        $changeClient = ! $existingColumns['client_id']['nullable'];
        $changeStatus = $existingColumns['status']['type_name'] !== 'varchar';
        if (DB::connection()->getDriverName() === 'sqlite') {
            $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'notifications'")->sql;
            $changeStatus = $changeStatus || preg_match('/check\s*\(\s*"?status"?\s+in\s*\(/i', $sql) === 1;
        }
        if ($changeClient || $changeStatus) {
            Schema::table('notifications', function (Blueprint $table) use ($changeClient, $changeStatus) {
                if ($changeClient) {
                    $table->foreignId('client_id')->nullable()->change();
                }
                if ($changeStatus) {
                    $table->string('status')->default('pending')->change();
                }
            });
        }

        $columns = [
            'user_id' => fn (Blueprint $table) => $table->foreignId('user_id')->nullable(),
            'barbershop_id' => fn (Blueprint $table) => $table->foreignId('barbershop_id')->nullable(),
            'recipient' => fn (Blueprint $table) => $table->string('recipient')->nullable(),
            'audience' => fn (Blueprint $table) => $table->string('audience')->nullable(),
            'event_id' => fn (Blueprint $table) => $table->uuid('event_id')->nullable(),
            'deduplication_key' => fn (Blueprint $table) => $table->string('deduplication_key')->nullable(),
            'secret' => fn (Blueprint $table) => $table->text('secret')->nullable(),
            'message' => fn (Blueprint $table) => $table->text('message')->nullable(),
            'provider_id' => fn (Blueprint $table) => $table->string('provider_id')->nullable(),
            'delivery_status' => fn (Blueprint $table) => $table->string('delivery_status')->nullable(),
            'attempts' => fn (Blueprint $table) => $table->unsignedSmallInteger('attempts')->default(0),
            'failure_code' => fn (Blueprint $table) => $table->string('failure_code')->nullable(),
            'next_attempt_at' => fn (Blueprint $table) => $table->timestamp('next_attempt_at')->nullable(),
            'locked_until' => fn (Blueprint $table) => $table->timestamp('locked_until')->nullable(),
            'lock_token' => fn (Blueprint $table) => $table->uuid('lock_token')->nullable(),
            'first_attempt_at' => fn (Blueprint $table) => $table->timestamp('first_attempt_at')->nullable(),
            'expires_at' => fn (Blueprint $table) => $table->timestamp('expires_at')->nullable(),
            'delivery_updated_at' => fn (Blueprint $table) => $table->timestamp('delivery_updated_at')->nullable(),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('notifications', $column)) {
                Schema::table('notifications', $definition);
            }
        }

        $this->ensureForeignKey('notifications', 'user_id', 'users', 'set null');
        $this->ensureForeignKey('notifications', 'barbershop_id', 'barbershops', 'set null');
        $this->ensureIndex('notifications', 'notifications_event_id_index', ['event_id']);
        $this->ensureIndex('notifications', 'notifications_deduplication_key_unique', ['deduplication_key'], true);
        $this->ensureIndex('notifications', 'notifications_provider_id_unique', ['provider_id'], true);
        $this->ensureIndex('notifications', 'notifications_type_status_next_attempt_at_index', ['type', 'status', 'next_attempt_at']);

        DB::table('notifications')->where('type', 'email')->where('status', 'pending')
            ->whereNull('deduplication_key')
            ->update(['status' => 'skipped', 'failure_code' => 'activation_cutoff']);

        if (! Schema::hasTable('notification_attempts')) {
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
        }
        $this->ensureForeignKey('notification_attempts', 'notification_id', 'notifications', 'cascade');
        $this->ensureForeignKey('notification_attempts', 'requested_by', 'users', 'set null');

        if (! Schema::hasTable('notification_webhook_events')) {
            Schema::create('notification_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id');
                $table->string('provider_id');
                $table->string('type');
                $table->timestamp('occurred_at');
                $table->timestamp('created_at');
            });
        }
        $this->ensureIndex('notification_webhook_events', 'notification_webhook_events_event_id_unique', ['event_id'], true);
        $this->ensureIndex('notification_webhook_events', 'notification_webhook_events_provider_id_index', ['provider_id']);
    }

    private function ensureIndex(string $table, string $name, array $columns, bool $unique = false): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns, $unique) {
                if ($unique) {
                    $blueprint->unique($columns, $name);
                } else {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    private function ensureForeignKey(string $table, string $column, string $referencedTable, string $onDelete): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                if ($foreignKey['foreign_table'] !== $referencedTable
                    || $foreignKey['foreign_columns'] !== ['id']
                    || strtolower($foreignKey['on_delete']) !== $onDelete) {
                    throw new RuntimeException("Unexpected foreign key on {$table}.{$column}; review the schema before continuing.");
                }

                return;
            }
        }
        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $onDelete) {
            $blueprint->foreign($column)->references('id')->on($referencedTable)->onDelete($onDelete);
        });
    }

    public function down(): void
    {
        // Multiple messages per booking cannot safely be reduced to the old unique key.
        throw new RuntimeException('This migration preserves delivery history and cannot be rolled back. Restore a backup instead.');
    }
};
