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
        Schema::create('order_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('courier_provider');
            $table->string('tracking_code')->nullable()->index();
            $table->string('consignment_id')->nullable()->index();
            $table->string('invoice_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->string('raw_status')->nullable();
            $table->decimal('delivery_charge', 10, 2)->default(0);
            $table->json('response_payload')->nullable();
            $table->text('booking_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_parcels');
    }
};
