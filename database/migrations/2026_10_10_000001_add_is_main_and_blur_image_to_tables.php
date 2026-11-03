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
        if (Schema::hasTable('product_images') && ! Schema::hasColumn('product_images', 'is_main')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->boolean('is_main')->default(false)->after('attribute_value')->index();
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'blur_image')) {
            Schema::table('products', function (Blueprint $table) {
                $table->text('blur_image')->nullable()->after('image');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_images') && Schema::hasColumn('product_images', 'is_main')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropIndex(['is_main']);
                $table->dropColumn('is_main');
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'blur_image')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('blur_image');
            });
        }
    }
};
