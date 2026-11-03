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
        Schema::create('courier_fraud_checker_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('courier')->index();
            $table->text('credential');
            $table->string('status')->default('active')->index();
            $table->timestamp('rate_limit_until')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courier_fraud_checker_credentials');
    }
};
