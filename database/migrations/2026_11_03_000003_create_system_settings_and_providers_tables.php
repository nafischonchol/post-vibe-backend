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
        // 1. System Settings (Global SaaS toggles, feature flags, defaults)
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 50)->default('general'); // general, ai, publishing, limits
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('group');
        });

        // 2. AI Providers (Admin-managed AI configurations)
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique(); // openrouter, gemini, openai
            $table->string('display_name');
            $table->text('encrypted_api_key')->nullable();
            $table->string('default_model')->nullable();
            $table->json('configuration')->nullable(); // temperature, max_tokens, retry_limit, timeout
            $table->string('status', 20)->default('active'); // active, inactive, error
            $table->timestamps();

            $table->index('status');
        });

        // 2b. Central AI Settings table (for OpenRouterService gateway)
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('openrouter');
            $table->text('api_key')->nullable();
            $table->string('default_model')->default('google/gemini-2.5-flash');
            $table->boolean('is_enabled')->default(true);
            $table->string('site_url')->nullable();
            $table->string('site_name')->nullable();
            $table->timestamps();
        });

        // 3. Platform Applications (Admin-managed SaaS OAuth Client credentials for YouTube, TikTok, Facebook)
        Schema::create('platform_applications', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 30)->unique(); // youtube, tiktok, facebook
            $table->string('client_id')->nullable();
            $table->text('encrypted_client_secret')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->json('configuration')->nullable(); // scopes, api_version, rate_limits
            $table->string('status', 20)->default('configured'); // configured, unconfigured, review_pending
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_applications');
        Schema::dropIfExists('ai_settings');
        Schema::dropIfExists('ai_providers');
        Schema::dropIfExists('system_settings');
    }
};
