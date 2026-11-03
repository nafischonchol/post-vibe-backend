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
        Schema::table('seo_settings', function (Blueprint $table) {
            $table->text('tiktok_access_token')->nullable()->after('tiktok_pixel_id');
            $table->string('tiktok_test_event_code')->nullable()->after('tiktok_access_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_settings', function (Blueprint $table) {
            $table->dropColumn(['tiktok_access_token', 'tiktok_test_event_code']);
        });
    }
};
