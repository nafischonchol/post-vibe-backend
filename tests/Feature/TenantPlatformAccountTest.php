<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantPlatformAccount;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantPlatformAccountTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected TenantUser $tenantUser;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme Media Corp',
            'slug' => 'acme-media',
            'email' => 'contact@acme.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->tenantUser = TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Creator',
            'email' => 'john@acme.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->tenantUser->createToken('tenant-auth', ['role:tenant'])->plainTextToken;
    }

    public function test_tenant_can_update_platform_account(): void
    {
        $account = TenantPlatformAccount::create([
            'tenant_id' => $this->tenant->id,
            'platform' => 'facebook',
            'account_name' => 'Sports Seekers',
            'external_account_id' => '1234567890',
            'encrypted_access_token' => 'valid-access-token-long-enough',
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/tenant/platform-accounts/{$account->id}", [
                'account_name' => 'Sports Seekers Official',
                'account_handle' => '@sportsseekers',
                'status' => 'inactive',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.account_name', 'Sports Seekers Official')
            ->assertJsonPath('data.account_handle', '@sportsseekers')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('tenant_platform_accounts', [
            'id' => $account->id,
            'account_name' => 'Sports Seekers Official',
            'status' => 'inactive',
        ]);
    }
}
