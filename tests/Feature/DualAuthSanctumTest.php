<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DualAuthSanctumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    public function test_admin_can_login_and_receive_sanctum_token(): void
    {
        $admin = Admin::create([
            'name' => 'Platform Superadmin',
            'email' => 'superadmin@postvibe.com',
            'password' => Hash::make('secret123'),
            'role' => 'superadmin',
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'superadmin@postvibe.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'resources' => ['token', 'admin'],
            ]);

        $this->assertNotEmpty($response->json('resources.token'));
    }

    public function test_tenant_user_can_login_and_receive_sanctum_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Alpha Studio',
            'slug' => 'alpha-studio',
            'email' => 'team@alphastudio.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => 'Alpha Owner',
            'email' => 'owner@alphastudio.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/tenant/login', [
            'email' => 'owner@alphastudio.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'resources' => ['token', 'tenant', 'user'],
            ]);

        $this->assertEquals($tenant->id, $response->json('resources.tenant.id'));
    }

    public function test_tenant_token_is_rejected_on_admin_protected_endpoints(): void
    {
        $tenant = Tenant::create([
            'name' => 'Beta Studio',
            'slug' => 'beta-studio',
            'email' => 'beta@studio.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => 'Beta Owner',
            'email' => 'owner@beta.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'status' => 'active',
        ]);

        $token = $user->createToken('tenant-auth', ['role:tenant'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        // Admin endpoints require role:admin ability
        $this->assertTrue(in_array($response->status(), [401, 403]));
    }
}
