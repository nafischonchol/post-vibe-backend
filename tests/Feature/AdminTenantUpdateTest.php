<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTenantUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);

        $this->admin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@postvibe.com',
            'password' => Hash::make('secret123'),
            'role' => 'superadmin',
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Original Studio',
            'slug' => 'original-studio',
            'email' => 'contact@originalstudio.com',
            'timezone' => 'UTC',
            'status' => 'active',
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner Person',
            'email' => 'owner@originalstudio.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_update_tenant_basic_information(): void
    {
        Sanctum::actingAs($this->admin, ['role:superadmin'], 'admin');

        $response = $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}", [
            'name' => 'Updated Studio Name',
            'email' => 'updated@originalstudio.com',
            'timezone' => 'Asia/Dhaka',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Tenant updated successfully.',
                'resources' => [
                    'id' => $this->tenant->id,
                    'name' => 'Updated Studio Name',
                    'email' => 'updated@originalstudio.com',
                    'timezone' => 'Asia/Dhaka',
                ],
            ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'name' => 'Updated Studio Name',
            'email' => 'updated@originalstudio.com',
            'timezone' => 'Asia/Dhaka',
        ]);
    }

    public function test_admin_can_update_tenant_via_patch_method(): void
    {
        Sanctum::actingAs($this->admin, ['role:superadmin'], 'admin');

        $response = $this->patchJson("/api/v1/admin/tenants/{$this->tenant->id}", [
            'name' => 'Patched Studio Name',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'resources' => [
                    'name' => 'Patched Studio Name',
                ],
            ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'name' => 'Patched Studio Name',
        ]);
    }

    public function test_admin_can_update_tenant_status_to_suspended(): void
    {
        Sanctum::actingAs($this->admin, ['role:superadmin'], 'admin');

        $response = $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->tenant->id,
            'status' => 'suspended',
        ]);
    }

    public function test_admin_can_update_tenant_plan(): void
    {
        Sanctum::actingAs($this->admin, ['role:superadmin'], 'admin');

        $plan = Plan::firstOrFail();

        $response = $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}", [
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_updating_tenant_email_fails_if_duplicate(): void
    {
        Sanctum::actingAs($this->admin, ['role:superadmin'], 'admin');

        Tenant::create([
            'name' => 'Another Studio',
            'slug' => 'another-studio',
            'email' => 'taken@domain.com',
            'status' => 'active',
        ]);

        $response = $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}", [
            'email' => 'taken@domain.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
