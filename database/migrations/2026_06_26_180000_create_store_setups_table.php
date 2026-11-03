<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_setups', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('bin_number')->nullable();
            $table->text('street_address')->nullable();
            $table->tinyInteger('division_id')->unsigned()->nullable();
            $table->smallInteger('district_id')->unsigned()->nullable();
            $table->mediumInteger('upazila_id')->unsigned()->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('youtube')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();

            $table->foreign('division_id')->references('id')->on('divisions')->nullOnDelete();
            $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            $table->foreign('upazila_id')->references('id')->on('upazilas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_setups');
    }
};
