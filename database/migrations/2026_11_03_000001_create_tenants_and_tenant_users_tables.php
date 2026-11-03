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
        // 1. Tenants table
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('status', 20)->default('active'); // active, suspended, pending
            $table->string('timezone', 50)->default('UTC');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // 2. Tenant Users table (Authentication entity for tenant portal)
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role', 20)->default('editor'); // owner, admin, editor, viewer
            $table->string('status', 20)->default('active'); // active, suspended
            $table->string('avatar')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'role']);
            $table->index('email');
        });

        // 3. Tenant Invitations
        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20)->default('editor');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
