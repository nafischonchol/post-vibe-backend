<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantRegistrationAndApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    public function test_tenant_can_self_register_and_defaults_to_pending_status(): void
    {
        $payload = [
            'name' => 'Apex Media Hub',
            'email' => 'contact@apexmedia.com',
            'timezone' => 'Asia/Dhaka',
            'owner_name' => 'John Creator',
            'owner_email' => 'john@apexmedia.com',
            'owner_password' => 'secret12345',
        ];

        $response = $this->postJson('/api/v1/tenant/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'resources' => [
                    'tenant' => [
                        'name' => 'Apex Media Hub',
                        'email' => 'contact@apexmedia.com',
                        'status' => 'pending',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Apex Media Hub',
            'email' => 'contact@apexmedia.com',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'email' => 'john@apexmedia.com',
            'role' => 'owner',
            'status' => 'pending',
        ]);
    }

    public function test_pending_tenant_cannot_login(): void
    {
        $this->postJson('/api/v1/tenant/register', [
            'name' => 'Apex Media Hub',
            'email' => 'contact@apexmedia.com',
            'timezone' => 'Asia/Dhaka',
            'owner_name' => 'John Creator',
            'owner_email' => 'john@apexmedia.com',
            'owner_password' => 'secret12345',
        ])->assertStatus(201);

        $loginResponse = $this->postJson('/api/v1/tenant/login', [
            'email' => 'john@apexmedia.com',
            'password' => 'secret12345',
        ]);

        $loginResponse->assertStatus(403)
            ->assertJson([
                'status' => 'error',
            ]);

        $this->assertStringContainsString('pending administrator approval', $loginResponse->json('message'));
    }

    public function test_admin_can_approve_pending_tenant_enabling_login(): void
    {
        $this->postJson('/api/v1/tenant/register', [
            'name' => 'Apex Media Hub',
            'email' => 'contact@apexmedia.com',
            'timezone' => 'Asia/Dhaka',
            'owner_name' => 'John Creator',
            'owner_email' => 'john@apexmedia.com',
            'owner_password' => 'secret12345',
        ])->assertStatus(201);

        $tenant = Tenant::where('email', 'contact@apexmedia.com')->firstOrFail();

        $admin = Admin::create([
            'name' => 'Superadmin',
            'email' => 'admin@postvibe.com',
            'password' => Hash::make('secret123'),
            'role' => 'superadmin',
        ]);

        Sanctum::actingAs($admin, ['role:superadmin'], 'admin');

        $approveResponse = $this->postJson("/api/v1/admin/tenants/{$tenant->id}/approve");

        $approveResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);

        // Now test tenant user login succeeds
        $loginResponse = $this->postJson('/api/v1/tenant/login', [
            'email' => 'john@apexmedia.com',
            'password' => 'secret12345',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'resources' => ['token', 'user', 'tenant'],
            ]);
    }

    public function test_admin_can_reject_pending_tenant_keeping_login_blocked(): void
    {
        $this->postJson('/api/v1/tenant/register', [
            'name' => 'Spam Media Hub',
            'email' => 'spam@spammedia.com',
            'timezone' => 'UTC',
            'owner_name' => 'Spammer',
            'owner_email' => 'spammer@spammedia.com',
            'owner_password' => 'secret12345',
        ])->assertStatus(201);

        $tenant = Tenant::where('email', 'spam@spammedia.com')->firstOrFail();

        $admin = Admin::create([
            'name' => 'Superadmin',
            'email' => 'admin@postvibe.com',
            'password' => Hash::make('secret123'),
            'role' => 'superadmin',
        ]);

        Sanctum::actingAs($admin, ['role:superadmin'], 'admin');

        $rejectResponse = $this->postJson("/api/v1/admin/tenants/{$tenant->id}/reject");

        $rejectResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'rejected',
        ]);

        $loginResponse = $this->postJson('/api/v1/tenant/login', [
            'email' => 'spammer@spammedia.com',
            'password' => 'secret12345',
        ]);

        $loginResponse->assertStatus(403)
            ->assertJson([
                'status' => 'error',
            ]);

        $this->assertStringContainsString('rejected', $loginResponse->json('message'));
    }
}
