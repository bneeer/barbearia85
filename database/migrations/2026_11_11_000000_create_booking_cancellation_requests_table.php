<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->text('email');
            $table->text('appointments');
            $table->text('cancelled_ids')->nullable();
            $table->boolean('allows_all')->default(false);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_cancellation_requests');
    }
};
