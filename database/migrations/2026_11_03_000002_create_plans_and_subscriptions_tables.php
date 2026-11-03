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
        // 1. Subscription Plans
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price_monthly', 10, 2)->default(0.00);
            $table->decimal('price_yearly', 10, 2)->default(0.00);
            $table->unsignedInteger('global_daily_quota')->default(10);
            $table->unsignedInteger('team_member_limit')->default(1);
            $table->unsignedBigInteger('storage_limit_mb')->default(1024); // 1GB default
            $table->json('features')->nullable();
            $table->string('status', 20)->default('active'); // active, archived
            $table->timestamps();

            $table->index('status');
        });

        // 2. Plan Platform Quotas
        Schema::create('plan_platform_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('platform', 30); // youtube, tiktok, facebook
            $table->unsignedInteger('daily_quota')->default(10);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'platform']);
        });

        // 3. Subscriptions
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 20)->default('active'); // active, cancelled, expired, trial
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->string('billing_cycle', 20)->default('monthly'); // monthly, yearly
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // 4. Tenant Quota Overrides (customized limits set by Admin)
        Schema::create('tenant_quota_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('global_daily_quota')->nullable();
            $table->json('custom_settings')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_quota_overrides');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_platform_quotas');
        Schema::dropIfExists('plans');
    }
};
