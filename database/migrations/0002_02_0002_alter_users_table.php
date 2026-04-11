<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// USERS (extend default)

// add_barbershop_and_role_to_users
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('barbershop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->default('barber');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['barbershop_id', 'role']);
        });
    }
};
