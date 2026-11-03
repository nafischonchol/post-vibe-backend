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
        Schema::create('platform_post_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publishing_schedule_id')->nullable()->constrained('publishing_schedules')->nullOnDelete();
            $table->string('platform', 50);
            $table->string('external_post_id', 255);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->decimal('engagement', 8, 4)->default(0.0000);
            $table->timestamp('collected_at')->useCurrent();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'platform']);
            $table->index(['publishing_schedule_id']);
            $table->index(['external_post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_post_analytics');
    }
};
