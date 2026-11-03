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
        Schema::create('seo_settings', function (Blueprint $table) {
            $table->id();
            $table->string('google_search_console_id')->nullable();
            $table->string('bing_webmaster_id')->nullable();
            $table->string('baidu_webmaster_id')->nullable();
            $table->string('yandex_webmaster_id')->nullable();

            $table->string('meta_pixel_id')->nullable();
            $table->text('meta_capi_token')->nullable();
            $table->string('google_analytics_id')->nullable();
            $table->string('tiktok_pixel_id')->nullable();

            $table->text('robots_txt')->nullable();
            $table->boolean('sitemap_enabled')->default(false);
            $table->json('robots_meta_content')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_settings');
    }
};
