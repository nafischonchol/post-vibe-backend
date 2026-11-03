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
        // Platform-specific AI Generated and Tenant-Editable Metadata
        Schema::create('video_platform_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->string('platform', 30); // youtube, tiktok, facebook
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();
            $table->json('tags')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('generation_status', 20)->default('pending'); // pending, generated, failed
            $table->boolean('is_manually_edited')->default(false);
            $table->string('model', 100)->nullable();
            $table->string('prompt_version', 20)->default('v1');
            $table->unsignedInteger('token_usage')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            // Unique constraint: Exactly one current metadata record per video and target platform
            $table->unique(['video_id', 'platform']);
            $table->index(['tenant_id', 'platform']);
            $table->index('generation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_platform_metadata');
    }
};
