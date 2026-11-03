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
        // 1. Tenant Publishing Settings (Timezone, preferred scheduling windows)
        Schema::create('tenant_publishing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('automatic_publishing_enabled')->default(false);
            $table->boolean('manual_publishing_enabled')->default(true);
            $table->string('timezone', 50)->default('UTC');
            $table->json('preferred_days')->nullable(); // ["monday", "wednesday", "friday"]
            $table->json('preferred_time_windows')->nullable(); // [{"start": "18:00", "end": "21:00"}]
            $table->timestamps();

            $table->unique('tenant_id');
        });

        // 2. Tenant Platform Settings
        Schema::create('tenant_platform_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 30);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('daily_quota_override')->nullable();
            $table->json('scheduling_settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform']);
        });

        // 3. Publishing Batches (High-level distribution intention)
        Schema::create('publishing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('publishing_mode', 20)->default('manual'); // manual, automatic
            $table->string('batch_status', 30)->default('draft');
            // draft, reserved, scheduled, processing, partially_published, published, failed, cancelled
            $table->boolean('analysis_enabled_snapshot')->default(false);
            $table->json('metadata_configuration_snapshot')->nullable();
            $table->timestamp('intended_publish_at')->nullable();
            $table->date('quota_date')->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_status', 'intended_publish_at'], 'tenant_batch_status_idx');
            $table->index(['tenant_id', 'quota_date']);
        });

        // 4. Publishing Schedules (Independent per-platform upload operation records)
        Schema::create('publishing_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publishing_batch_id')->constrained('publishing_batches')->cascadeOnDelete();
            $table->string('platform', 30); // youtube, tiktok, facebook
            $table->foreignId('tenant_platform_account_id')->nullable()->constrained('tenant_platform_accounts')->nullOnDelete();
            $table->foreignId('video_platform_metadata_id')->nullable()->constrained('video_platform_metadata')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('upload_status', 30)->default('pending');
            // pending, scheduled, queued, uploading, processing, published, retrying, failed, cancelled, unknown
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('external_post_id', 150)->nullable();
            $table->string('external_post_url', 500)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['publishing_batch_id', 'platform', 'tenant_platform_account_id'], 'batch_platform_account_unique');
            $table->index(['upload_status', 'scheduled_at']);
            $table->index(['tenant_id', 'platform', 'upload_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publishing_schedules');
        Schema::dropIfExists('publishing_batches');
        Schema::dropIfExists('tenant_platform_settings');
        Schema::dropIfExists('tenant_publishing_settings');
    }
};
