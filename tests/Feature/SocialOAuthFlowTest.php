<?php

namespace Tests\Feature;

use App\Models\PlatformApplication;
use App\Models\Tenant;
use App\Models\TenantPlatformAccount;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SocialOAuthFlowTest extends TestCase
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

    public function test_tenant_can_generate_oauth_redirect_url_for_youtube(): void
    {
        PlatformApplication::create([
            'platform' => 'youtube',
            'client_id' => 'google-client-id-xyz',
            'encrypted_client_secret' => 'google-client-secret-abc',
            'redirect_uri' => 'http://localhost:8000/oauth/youtube/callback',
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/tenant/platform-accounts/oauth/youtube/redirect');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'authorization_url',
            ]);

        $authUrl = $response->json('authorization_url');
        $this->assertStringContainsString('accounts.google.com', $authUrl);
        $this->assertStringContainsString('google-client-id-xyz', $authUrl);
    }

    public function test_tenant_can_generate_oauth_redirect_in_simulated_mode(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/v1/tenant/platform-accounts/oauth/tiktok/redirect');

        $response->assertStatus(200);
        $authUrl = $response->json('authorization_url');
        $this->assertStringContainsString('/oauth/tiktok/callback', $authUrl);
        $this->assertStringContainsString('mock_auth_code_', $authUrl);
    }

    public function test_oauth_callback_creates_or_updates_tenant_platform_account(): void
    {
        $state = 'test_random_state_token_123';
        Cache::put("oauth_state_{$state}", [
            'tenant_id' => $this->tenant->id,
            'platform' => 'youtube',
            'created_at' => now()->timestamp,
        ], 900);

        $response = $this->get('/oauth/youtube/callback?code=mock_auth_code_999&state=' . $state);

        $response->assertStatus(302);
        $response->assertRedirectContains('/tenant/platform-accounts?connected=youtube');

        $this->assertDatabaseHas('tenant_platform_accounts', [
            'tenant_id' => $this->tenant->id,
            'platform' => 'youtube',
            'status' => 'active',
        ]);
    }

    public function test_oauth_callback_rejects_invalid_or_tampered_state(): void
    {
        $response = $this->get('/oauth/youtube/callback?code=some_code&state=invalid_tampered_state');

        $response->assertStatus(302);
        $response->assertRedirectContains('/tenant/platform-accounts?error=');
    }
}
