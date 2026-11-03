<?php

use App\Enums\OrderStatusEnum;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');

            $table->json('client_snapshot')->nullable();

            $table->decimal('total_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('grand_total', 10, 2);
            $table->string('status')->default(OrderStatusEnum::PLACED->value);
            $table->string('parcel_booking_status')->nullable()->default('none');
            $table->string('latest_courier_provider')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('admins')->onDelete('set null');
            $table->timestamps();

            $table->decimal('tax_amount', 10, 2)->default(0.00);
            $table->decimal('delivery_charge', 10, 2)->nullable()->default(null);
            $table->decimal('paid_amount', 10, 2);
            $table->decimal('change_amount', 10, 2)->default(0.00);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
