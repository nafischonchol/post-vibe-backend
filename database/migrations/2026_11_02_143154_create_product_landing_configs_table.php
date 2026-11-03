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
        Schema::create('product_landing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();

            // 1. Urgency / Flash Sale Countdown Timer
            $table->boolean('timer_enabled')->default(false);
            $table->dateTime('timer_end_time')->nullable();
            $table->string('timer_title')->nullable()->default('আজকের বিশেষ অফার শেষ হতে বাকি');

            // 2. Stock Scarcity Counter
            $table->boolean('stock_scarcity_enabled')->default(true);
            $table->integer('stock_scarcity_threshold')->default(10);
            $table->string('stock_scarcity_text')->nullable()->default('সীমিত স্টক! দ্রুত শেষ হয়ে যাচ্ছে');

            // 3. WhatsApp Direct Order & Assistance
            $table->boolean('whatsapp_order_enabled')->default(true);
            $table->string('whatsapp_custom_number')->nullable();
            $table->text('whatsapp_custom_message')->nullable();

            // 4. Social Proof / Recent Activity
            $table->boolean('recent_sales_ticker_enabled')->default(true);

            // 5. Delivery timeline & How to use
            $table->string('delivery_timeline_inside')->nullable()->default('২৪ থেকে ৪৮ ঘণ্টার মধ্যে ডেলিভারি');
            $table->string('delivery_timeline_outside')->nullable()->default('২ থেকে ৩ কার্যদিবসের মধ্যে ডেলিভারি');
            $table->json('how_to_use_steps')->nullable(); // array of { step, title, desc }

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_landing_configs');
    }
};
