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
        // 1. Transactional Outbox (Guarantees durable queue dispatch from DB transactions)
        Schema::create('transactional_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending, dispatched, failed
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        // 2. Publishing Audit Logs
        Schema::create('publishing_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publishing_batch_id')->nullable()->constrained('publishing_batches')->nullOnDelete();
            $table->foreignId('publishing_schedule_id')->nullable()->constrained('publishing_schedules')->nullOnDelete();
            $table->string('event', 100);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        // 3. AI Usage Logs (Track token consumption, cost, and budget limits)
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('operation', 50); // video_analysis, platform_metadata
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost', 8, 4)->default(0.0000);
            $table->string('request_status', 20)->default('success'); // success, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['provider', 'created_at']);
        });

        // 4. Admin Audit Logs
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('publishing_audit_logs');
        Schema::dropIfExists('transactional_outbox');
    }
};
