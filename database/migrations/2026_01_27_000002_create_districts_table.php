<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->smallInteger('id')->unsigned()->primary();
            $table->tinyInteger('division_id')->unsigned();
            $table->string('name', 25);
            $table->string('bn_name', 25);
            $table->string('lat', 15)->nullable();
            $table->string('lon', 15)->nullable();

            $table->foreign('division_id')->references('id')->on('divisions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
