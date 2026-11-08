<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('services');
            $table->json('products');
            $table->unsignedBigInteger('services_total_cents');
            $table->unsignedBigInteger('products_total_cents');
            $table->unsignedBigInteger('discount_cents');
            $table->unsignedBigInteger('total_cents');
            $table->string('payment_method', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_checkouts');
    }
};
