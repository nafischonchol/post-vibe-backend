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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('chat_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('sender_type'); // customer, ai, admin
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('file_url')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable(); // image, pdf, document, spreadsheet, video, file
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
