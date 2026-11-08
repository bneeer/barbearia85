<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barbershops', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('zip_code', 8)->nullable();
            $table->json('opening_hours')->nullable();
            $table->boolean('is_open')->default(true);
        });

        Schema::create('barbershop_user', function (Blueprint $table) {
            $table->foreignId('barbershop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['barbershop_id', 'user_id']);
        });

        DB::table('users')
            ->where('role', 'barber')
            ->whereNotNull('barbershop_id')
            ->orderBy('id')
            ->eachById(function ($user) {
                DB::table('barbershop_user')->insertOrIgnore([
                    'barbershop_id' => $user->barbershop_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('barbershop_user');

        Schema::table('barbershops', function (Blueprint $table) {
            $table->dropColumn(['description', 'address', 'zip_code', 'opening_hours', 'is_open']);
        });
    }
};
