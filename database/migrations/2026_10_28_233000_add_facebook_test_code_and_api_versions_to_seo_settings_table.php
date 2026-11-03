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
            $table->string('meta_test_event_code')->nullable()->after('meta_capi_token');
            $table->string('meta_api_version')->nullable()->default('v20.0')->after('meta_test_event_code');
            $table->string('tiktok_api_version')->nullable()->default('v1.3')->after('tiktok_test_event_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_test_event_code', 'meta_api_version', 'tiktok_api_version']);
        });
    }
};
