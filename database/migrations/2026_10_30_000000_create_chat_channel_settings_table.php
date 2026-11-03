<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->unique(); // facebook, whatsapp
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials')->nullable(); // encrypted:array
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_channel_settings');
    }
};
