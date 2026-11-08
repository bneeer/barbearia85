<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->unique(['appointment_id', 'type']);
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['user_id', 'start_time', 'end_time']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'start_time', 'end_time']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['appointment_id', 'type']);
            $table->dropConstrainedForeignId('appointment_id');
            $table->dropColumn('payload');
        });
    }
};
