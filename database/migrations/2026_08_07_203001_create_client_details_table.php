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
        Schema::create('client_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('company_name')->nullable();
            $table->string('country')->default('BD');
            $table->string('website_or_fb')->nullable();
            $table->string('trade_license')->nullable();
            $table->text('company_address');
            $table->string('contact_name');
            $table->string('position')->nullable();
            $table->string('business_type')->nullable();
            $table->string('hear_about_us')->nullable();
            $table->json('interested_categories')->nullable();
            $table->text('business_introduction')->nullable();
            $table->boolean('nda_agreed')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_details');
    }
};
