<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_grants', function (Blueprint $table) {
            $table->id();
            $table->string('email_key', 64)->unique();
            $table->text('email')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('notification_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_grants');
    }
};
