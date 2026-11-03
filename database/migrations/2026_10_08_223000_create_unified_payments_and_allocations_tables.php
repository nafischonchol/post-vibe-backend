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
        // Drop legacy purchase payment tables if they exist
        Schema::dropIfExists('purchase_payment_allocations');
        Schema::dropIfExists('purchase_payments');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 100)->unique();
            $table->string('type', 20); // in, out (PaymentTypeEnum)
            $table->string('status', 20)->default('completed'); // completed, voided (PaymentStatusEnum)
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->dateTime('payment_date');
            $table->string('payment_method', 50)->default('cash');
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('allocatable_type', 50); // 'order' or 'purchase'
            $table->unsignedBigInteger('allocatable_id');
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();

            $table->index(['allocatable_type', 'allocatable_id'], 'allocatable_index');
            $table->unique(['payment_id', 'allocatable_type', 'allocatable_id'], 'unique_payment_allocation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
