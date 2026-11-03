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
        Schema::create('blog_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('url', 500);
            $table->foreignId('default_category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_publish')->default(true);
            $table->timestamp('last_curated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_curated_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_source_id')->nullable()->constrained('blog_sources')->nullOnDelete();
            $table->string('original_url_hash', 64)->unique();
            $table->text('original_url');
            $table->string('original_title')->nullable();
            $table->foreignId('blog_post_id')->nullable()->constrained('blog_posts')->nullOnDelete();
            $table->enum('status', ['pending', 'published', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_curated_logs');
        Schema::dropIfExists('blog_sources');
    }
};
