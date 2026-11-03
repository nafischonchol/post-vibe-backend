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
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('conversation_state')->default('idle')->after('status')->index(); // idle, collecting_order_info, awaiting_otp
            $table->json('pending_order_data')->nullable()->after('conversation_state');
            $table->boolean('is_phone_verified')->default(false)->after('pending_order_data');
        });

        Schema::create('client_social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('channel'); // facebook, whatsapp
            $table->string('external_id')->index(); // FB PSID or WhatsApp phone
            $table->json('profile_data')->nullable(); // name, avatar, etc.
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_social_identities');

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['conversation_state', 'pending_order_data', 'is_phone_verified']);
        });
    }
};
