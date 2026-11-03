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
        // 1. Video Sources (Instagram, Facebook pages, TikTok profiles, etc.)
        Schema::create('video_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 30); // instagram, facebook, youtube, tiktok
            $table->string('source_identifier'); // username, page_id, channel_id
            $table->string('source_url', 500);
            $table->string('name');
            $table->string('status', 20)->default('active'); // active, paused, error
            $table->unsignedInteger('scan_frequency')->default(60); // in minutes
            $table->timestamp('last_scanned_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'source_identifier']);
            $table->index(['tenant_id', 'status']);
        });

        // 2. Videos (Ingested source media and lifecycle status)
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('video_sources')->nullOnDelete();
            $table->string('source_platform', 30);
            $table->string('source_post_id', 150);
            $table->string('source_post_url', 500)->nullable();
            $table->uuid('video_uid')->unique();
            $table->string('video_hash', 64)->nullable(); // SHA-256 for duplicate detection
            $table->text('original_caption')->nullable();
            $table->json('original_hashtags')->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('temporary_storage_path', 500)->nullable(); // S3 object key
            $table->unsignedInteger('duration')->nullable(); // in seconds
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->string('processing_status', 30)->default('discovered');
            // discovered, downloading, stored, analyzing, generating_metadata, ready, processing_failed, archived
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_id', 'source_post_id'], 'tenant_source_post_unique');
            $table->index(['tenant_id', 'processing_status', 'created_at']);
            $table->index(['video_hash']);
        });

        // 3. Video Analysis Results (Cached AI content analysis)
        Schema::create('video_analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('provider', 50); // openrouter, gemini
            $table->string('model', 100);
            $table->string('analysis_version', 20)->default('v1');
            $table->text('summary')->nullable();
            $table->longText('transcript')->nullable();
            $table->json('visual_context')->nullable();
            $table->json('structured_result')->nullable();
            $table->string('status', 20)->default('completed'); // completed, failed
            $table->unsignedInteger('token_usage')->default(0);
            $table->decimal('estimated_cost', 8, 4)->default(0.0000);
            $table->timestamps();

            $table->unique(['video_id', 'model', 'analysis_version'], 'video_model_analysis_unique');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_analysis_results');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('video_sources');
    }
};
