<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->string('protocol', 32)->unique();
            $table->dateTime('received_at');
            $table->string('channel', 30);
            $table->string('type', 30);
            $table->string('other_type', 120)->nullable();
            $table->date('suggested_due_date')->nullable();
            $table->date('due_date');
            $table->string('due_reason', 500)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('identity_verified')->default(false);
            $table->string('identity_method', 30)->nullable();
            $table->boolean('internal_search_done')->default(false);
            $table->boolean('external_search_done')->default(false);
            $table->boolean('shared_agents_done')->default(false);
            $table->boolean('response_sent')->default(false);
            $table->string('status', 20)->default('open');
            $table->string('result', 20)->nullable();
            $table->string('result_reason', 500)->nullable();
            $table->string('retained_categories', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        Schema::create('privacy_request_clients', function (Blueprint $table) {
            $table->foreignId('privacy_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->primary(['privacy_request_id', 'client_id']);
        });

        Schema::create('privacy_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_request_events');
        Schema::dropIfExists('privacy_request_clients');
        Schema::dropIfExists('privacy_requests');
    }
};
