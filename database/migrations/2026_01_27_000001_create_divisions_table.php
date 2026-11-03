<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table) {
            $table->tinyInteger('id')->unsigned()->primary();
            $table->string('name', 25);
            $table->string('bn_name', 25);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('divisions');
    }
};
