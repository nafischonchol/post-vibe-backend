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
        Schema::create('product_specification_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_specification_id')
                ->constrained('product_specifications')
                ->onDelete('cascade')
                ->name('ps_val_spec_fk'); // shorter constraint name to prevent length issues

            $table->foreignId('attribute_value_id')
                ->constrained('attribute_values')
                ->onDelete('cascade')
                ->name('ps_val_value_fk');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_specification_values');
    }
};
