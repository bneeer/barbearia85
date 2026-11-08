<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable();
        });

        Schema::create('barber_service', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barber_service');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
