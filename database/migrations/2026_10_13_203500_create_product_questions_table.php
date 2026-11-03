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
        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->text('question');

            $table->text('answer')->nullable();
            $table->string('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();

            $table->unsignedInteger('likes_count')->default(0);
            $table->string('status')->default('approved')->index();

            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_questions');
    }
};
