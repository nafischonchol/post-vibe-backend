<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->integer('id')->unsigned()->primary();
            $table->mediumInteger('upazila_id')->unsigned();
            $table->string('name', 25);
            $table->string('bn_name', 25);

            $table->foreign('upazila_id')->references('id')->on('upazilas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
