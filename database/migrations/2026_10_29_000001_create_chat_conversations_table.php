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
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel')->default('web'); // web, facebook, whatsapp
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('external_user_id')->nullable()->index(); // For Facebook PSID, WhatsApp phone
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('agent_mode')->default('ai'); // ai, human
            $table->string('status')->default('open'); // open, resolved, closed
            $table->text('conversation_summary')->nullable();
            $table->unsignedBigInteger('last_summarized_message_id')->nullable()->index();
            $table->timestamp('summary_updated_at')->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
