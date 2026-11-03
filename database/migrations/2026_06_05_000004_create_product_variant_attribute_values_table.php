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
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->id();

            // Link to the variant
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->onDelete('cascade')
                ->name('pv_attr_val_variant_fk'); // shorter constraint name to prevent length issues

            // Link to the attribute (e.g. Color)
            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->onDelete('cascade')
                ->name('pv_attr_val_attribute_fk');

            // Link to the attribute value (e.g. Red)
            $table->foreignId('attribute_value_id')
                ->constrained('attribute_values')
                ->onDelete('cascade')
                ->name('pv_attr_val_value_fk');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
