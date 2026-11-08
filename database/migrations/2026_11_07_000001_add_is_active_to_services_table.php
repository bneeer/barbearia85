<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'description')) {
            Schema::table('services', function (Blueprint $table) {
                $table->text('description')->nullable();
            });
        }

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        // Description may have been created by the original services migration.
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
