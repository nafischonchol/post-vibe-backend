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
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('invoice_no')->nullable()->unique()->after('id');
            $table->foreignId('client_id')->nullable()->after('invoice_no')->constrained('clients')->onDelete('set null');
            $table->dateTime('purchase_date')->nullable()->after('supplier_name');
            $table->decimal('paid_amount', 12, 2)->default(0.00)->after('total_amount');
            $table->decimal('due_amount', 12, 2)->default(0.00)->after('paid_amount');
            $table->string('payment_status')->default('unpaid')->after('due_amount'); // unpaid, partially_paid, paid
            $table->text('note')->nullable()->after('status');

            try {
                $table->dropForeign(['created_by_id']);
            } catch (\Throwable $e) {
                // Ignore if foreign key didn't exist or SQLite
            }
            $table->foreign('created_by_id')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no')->unique();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('account_id')->constrained('accounts')->onDelete('cascade');
            $table->decimal('total_paid', 12, 2);
            $table->dateTime('payment_date');
            $table->string('payment_method')->default('cash');
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_payment_id')->constrained('purchase_payments')->onDelete('cascade');
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_payment_allocations');
        Schema::dropIfExists('purchase_payments');

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn([
                'invoice_no',
                'client_id',
                'purchase_date',
                'paid_amount',
                'due_amount',
                'payment_status',
                'note',
            ]);
        });
    }
};
