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
        Schema::table('tenant_publishing_settings', function (Blueprint $table) {
            $table->unsignedInteger('publishing_interval_minutes')->default(60)->after('automatic_publishing_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_publishing_settings', function (Blueprint $table) {
            $table->dropColumn('publishing_interval_minutes');
        });
    }
};
