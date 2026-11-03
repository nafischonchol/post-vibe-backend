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
        Schema::create('ai_chat_settings', function (Blueprint $table) {
            $table->id();
            $table->string('model_name')->default('google/gemini-2.5-flash');
            $table->text('system_prompt')->nullable();
            $table->boolean('is_ai_enabled')->default(true);
            $table->decimal('temperature', 3, 2)->default(0.3);
            $table->unsignedInteger('max_tokens')->default(1000);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chat_settings');
    }
};
