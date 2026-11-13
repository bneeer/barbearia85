<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
        });

        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barbershop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 10);
            $table->decimal('stock_quantity', 12, 3)->default(0);
            $table->decimal('min_stock', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['barbershop_id', 'name']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barbershop_id')->constrained()->cascadeOnDelete();
            $table->morphs('stockable');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_checkout_id')
                ->nullable()->constrained('appointment_checkouts')->nullOnDelete();
            $table->string('stockable_name');
            $table->string('unit', 10);
            $table->string('type', 20);
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_before', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->string('reason');
            $table->timestamps();
            $table->index(['barbershop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('supplies');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['stock_quantity', 'min_stock']);
        });
    }
};
