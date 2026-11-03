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
        Schema::table('categories', function (Blueprint $table) {
            $table->longText('seo_bottom_content')->nullable()->after('meta_description');
            $table->longText('seo_bottom_content_bn')->nullable()->after('seo_bottom_content');
            $table->json('faqs')->nullable()->after('seo_bottom_content_bn');
            $table->json('faqs_bn')->nullable()->after('faqs');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->longText('seo_bottom_content')->nullable()->after('meta_description');
            $table->longText('seo_bottom_content_bn')->nullable()->after('seo_bottom_content');
            $table->json('faqs')->nullable()->after('seo_bottom_content_bn');
            $table->json('faqs_bn')->nullable()->after('faqs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['seo_bottom_content', 'seo_bottom_content_bn', 'faqs', 'faqs_bn']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['seo_bottom_content', 'seo_bottom_content_bn', 'faqs', 'faqs_bn']);
        });
    }
};
