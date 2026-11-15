<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table) {
            $table->boolean('loyalty_enabled')->default(false);
            $table->unsignedInteger('loyalty_stamp_goal')->default(5);
            $table->string('loyalty_reward_description')->nullable();
            $table->timestamp('loyalty_enabled_at')->nullable();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barbershop_id')->constrained()->cascadeOnDelete();
            $table->string('identity_hash', 64);
            $table->unsignedInteger('stamps_balance')->default(0);
            $table->timestamps();
            $table->unique(['barbershop_id', 'identity_hash']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_checkout_id')->nullable()->unique()
                ->constrained('appointment_checkouts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 12);
            $table->integer('stamps');
            $table->string('reward_description')->nullable();
            $table->timestamps();
            $table->index(['loyalty_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');

        Schema::table('barbershops', function (Blueprint $table) {
            $table->dropColumn([
                'loyalty_enabled',
                'loyalty_stamp_goal',
                'loyalty_reward_description',
                'loyalty_enabled_at',
            ]);
        });
    }
};
