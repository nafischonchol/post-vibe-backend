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
        Schema::create('category_landing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained('categories')->cascadeOnDelete();

            // 1. Urgency / Countdown Timer
            $table->boolean('timer_enabled')->default(false);
            $table->dateTime('timer_end_time')->nullable();
            $table->string('timer_title')->nullable()->default('স্পেশাল অফার শেষ হতে বাকি');

            // 2. WhatsApp Direct Order & Assistance
            $table->boolean('whatsapp_order_enabled')->default(true);
            $table->string('whatsapp_custom_number')->nullable();
            $table->text('whatsapp_custom_message')->nullable();

            // 3. Social Proof / Recent Activity
            $table->boolean('recent_sales_ticker_enabled')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_landing_configs');
    }
};
