<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_service', function (Blueprint $table) {
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['appointment_id', 'service_id']);
        });

        DB::table('appointments')->orderBy('id')->each(function (object $appointment): void {
            DB::table('appointment_service')->insert([
                'appointment_id' => $appointment->id,
                'service_id' => $appointment->service_id,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_service');
    }
};
