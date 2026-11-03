<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upazilas', function (Blueprint $table) {
            $table->mediumInteger('id')->unsigned()->primary();
            $table->smallInteger('district_id')->unsigned();
            $table->string('name', 25);
            $table->string('bn_name', 25);

            $table->foreign('district_id')->references('id')->on('districts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upazilas');
    }
};
