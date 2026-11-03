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
            $table->string('landing_headline')->nullable()->after('faqs_bn');
            $table->text('landing_subtitle')->nullable()->after('landing_headline');
            $table->string('landing_video_url')->nullable()->after('landing_subtitle');
            $table->string('landing_badge')->nullable()->after('landing_video_url');
            $table->json('landing_benefits')->nullable()->after('landing_badge');
            $table->json('landing_steps')->nullable()->after('landing_benefits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'landing_headline',
                'landing_subtitle',
                'landing_video_url',
                'landing_badge',
                'landing_benefits',
                'landing_steps',
            ]);
        });
    }
};
