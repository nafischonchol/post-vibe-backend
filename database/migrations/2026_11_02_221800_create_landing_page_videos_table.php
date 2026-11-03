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
        Schema::create('landing_page_videos', function (Blueprint $table) {
            $table->id();

            // Direct Normalized Foreign Keys
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();

            // Video URL & Parsed YouTube Video ID
            $table->string('video_url', 500);
            $table->string('video_id', 50)->nullable();

            // Optional Card Overrides
            $table->string('title')->nullable();
            $table->string('badge_text')->nullable();

            // Featured/Linked Product for the bottom shoppable pill card
            $table->foreignId('featured_product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
            $table->index(['category_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_videos');
    }
};
