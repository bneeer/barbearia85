<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index('appointment_id');
            $table->enum('type', ['whatsapp', 'email']);
            $table->string('template');
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('barbershop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient')->nullable();
            $table->string('audience')->nullable();
            $table->uuid('event_id')->nullable()->index();
            $table->string('deduplication_key')->nullable()->unique();
            $table->text('secret')->nullable();
            $table->text('message')->nullable();
            $table->string('provider_id')->nullable()->unique();
            $table->string('delivery_status')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->uuid('lock_token')->nullable();
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('delivery_updated_at')->nullable();
            $table->index(['type', 'status', 'next_attempt_at']);
            $table->timestamps();
        });

        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('result')->default('processing');
            $table->string('code')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('finished_at')->nullable();
        });

        Schema::create('notification_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('provider_id')->index();
            $table->string('type');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_webhook_events');
        Schema::dropIfExists('notification_attempts');
        Schema::dropIfExists('notifications');
    }
};
