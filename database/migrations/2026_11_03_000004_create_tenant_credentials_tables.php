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
        // 1. Tenant Connected Social Accounts
        Schema::create('tenant_platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 30); // youtube, tiktok, facebook
            $table->string('external_account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_handle')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('encrypted_access_token');
            $table->text('encrypted_refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status', 20)->default('active'); // active, expired, revoked, needs_reconnect
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_account_id'], 'tenant_platform_account_unique');
            $table->index(['tenant_id', 'platform']);
            $table->index('token_expires_at');
        });

        // 2. Tenant AI Credentials (Optional: BYOK - Bring Your Own Key)
        Schema::create('tenant_ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 50); // openrouter, gemini, openai
            $table->text('encrypted_api_key');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_credentials');
        Schema::dropIfExists('tenant_platform_accounts');
    }
};
