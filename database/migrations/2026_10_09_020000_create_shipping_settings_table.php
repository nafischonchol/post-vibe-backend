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
        Schema::create('shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('inside_dhaka_charge', 10, 2)->default(60.00);
            $table->decimal('outside_dhaka_charge', 10, 2)->default(120.00);
            $table->boolean('is_free_shipping_active')->default(false);
            $table->decimal('free_shipping_threshold', 10, 2)->nullable()->default(1500.00);
            $table->string('estimated_delivery_inside_dhaka')->nullable()->default('2-3 business days');
            $table->string('estimated_delivery_outside_dhaka')->nullable()->default('3-5 business days');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_settings');
    }
};
