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
        // 1. Tenant Global Daily Quota Aggregates (Materialized Counter for Fast Lock & Check)
        Schema::create('tenant_daily_quota_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('quota_date');
            $table->unsignedInteger('quota_limit')->default(10);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->unsignedInteger('published_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'quota_date']);
        });

        // 2. Tenant Per-Platform Daily Quota Aggregates
        Schema::create('tenant_platform_daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 30); // youtube, tiktok, facebook
            $table->date('quota_date');
            $table->unsignedInteger('quota_limit')->default(10);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->unsignedInteger('published_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'quota_date']);
        });

        // 3. Quota Reservations (Source of Truth Ledger for Batch Operations)
        Schema::create('quota_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publishing_batch_id')->constrained('publishing_batches')->cascadeOnDelete();
            $table->date('quota_date');
            $table->string('status', 20)->default('reserved'); // reserved, consumed, released
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'quota_date', 'status']);
        });

        // 4. Quota Reservation Items (Source of Truth Ledger for Platform Upload Operations)
        Schema::create('quota_reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quota_reservation_id')->constrained('quota_reservations')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 30);
            $table->foreignId('publishing_schedule_id')->nullable()->constrained('publishing_schedules')->nullOnDelete();
            $table->string('status', 20)->default('reserved'); // reserved, consumed, released
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['quota_reservation_id', 'platform']);
            $table->index(['tenant_id', 'platform', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quota_reservation_items');
        Schema::dropIfExists('quota_reservations');
        Schema::dropIfExists('tenant_platform_daily_usage');
        Schema::dropIfExists('tenant_daily_quota_usage');
    }
};
