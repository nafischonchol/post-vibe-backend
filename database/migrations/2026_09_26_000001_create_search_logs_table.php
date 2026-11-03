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
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('query', 255);
            $table->string('normalized_query', 255)->index();
            $table->unsignedInteger('results_count')->default(0)->index();
            $table->string('source', 50)->default('autocomplete');
            $table->string('visitor_id', 100)->nullable()->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('clicked_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'results_count']);
            $table->index(['normalized_query', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
