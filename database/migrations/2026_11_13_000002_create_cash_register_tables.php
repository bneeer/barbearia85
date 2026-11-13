<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barbershop_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('opening_amount_cents');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('expected_cash_cents')->nullable();
            $table->unsignedBigInteger('expected_pix_cents')->nullable();
            $table->unsignedBigInteger('expected_credit_card_cents')->nullable();
            $table->unsignedBigInteger('expected_debit_card_cents')->nullable();
            $table->unsignedBigInteger('counted_cash_cents')->nullable();
            $table->unsignedBigInteger('counted_pix_cents')->nullable();
            $table->unsignedBigInteger('counted_credit_card_cents')->nullable();
            $table->unsignedBigInteger('counted_debit_card_cents')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->index(['barbershop_id', 'closed_at']);
        });

        Schema::create('cash_register_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 255);
            $table->timestamps();
            $table->index(['cash_register_id', 'created_at']);
        });

        Schema::table('appointment_checkouts', function (Blueprint $table) {
            $table->foreignId('cash_register_id')->nullable()->after('appointment_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_id');
        });

        Schema::dropIfExists('cash_register_withdrawals');
        Schema::dropIfExists('cash_registers');
    }
};
