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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('landing_offer_id')->nullable()->after('coupon_code')->constrained('landing_tier_offers')->nullOnDelete();
            $table->json('offer_snapshot')->nullable()->after('landing_offer_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_gift')->default(false)->after('total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('is_gift');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['landing_offer_id']);
            $table->dropColumn(['landing_offer_id', 'offer_snapshot']);
        });
    }
};
