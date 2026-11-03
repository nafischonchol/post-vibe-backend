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
        Schema::create('landing_tier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('title'); // e.g. "১টি নিলে সাধারণ মূল্য", "২টি নিলে বিশেষ অফার"
            $table->string('subtitle')->nullable(); // e.g. "ফ্রি ডেলিভারি + আকর্ষণীয় গিফট"
            $table->decimal('discount_amount', 10, 2)->default(0.00); // Fixed discount in BDT
            $table->decimal('discount_percentage', 5, 2)->default(0.00); // Percentage discount if any
            $table->boolean('is_free_shipping')->default(false);
            $table->string('gift_text')->nullable(); // e.g. "১টি কোরিয়ান শিট মাস্ক ফ্রি"
            $table->foreignId('gift_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('gift_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('badge_text')->nullable(); // e.g. "জনপ্রিয়", "বেস্ট ভ্যালু"
            $table->boolean('is_popular')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_tier_offers');
    }
};
