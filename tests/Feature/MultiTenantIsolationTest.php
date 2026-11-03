<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Video;
use App\Models\VideoSource;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    public function test_tenant_cannot_read_or_access_another_tenants_data(): void
    {
        // 1. Setup Tenant A
        $tenantA = Tenant::create([
            'name' => 'Studio A',
            'slug' => 'studio-a',
            'email' => 'a@studio.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $userA = TenantUser::create([
            'tenant_id' => $tenantA->id,
            'name' => 'User A',
            'email' => 'usera@studio.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $tokenA = $userA->createToken('tenant-auth', ['role:tenant'])->plainTextToken;

        // 2. Setup Tenant B
        $tenantB = Tenant::create([
            'name' => 'Studio B',
            'slug' => 'studio-b',
            'email' => 'b@studio.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $userB = TenantUser::create([
            'tenant_id' => $tenantB->id,
            'name' => 'User B',
            'email' => 'userb@studio.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $tokenB = $userB->createToken('tenant-auth', ['role:tenant'])->plainTextToken;

        // 3. Create Video Source and Video for Tenant A
        TenantContext::setTenant($tenantA);
        $sourceA = VideoSource::create([
            'tenant_id' => $tenantA->id,
            'platform' => 'youtube',
            'source_identifier' => '@studio_a_channel',
            'source_url' => 'https://youtube.com/@studio_a_channel',
            'name' => 'Studio A Ingest',
            'scan_frequency' => 30,
            'status' => 'active',
        ]);

        $videoA = Video::create([
            'tenant_id' => $tenantA->id,
            'source_id' => $sourceA->id,
            'source_platform' => 'youtube',
            'source_post_id' => 'post_a_100',
            'original_caption' => 'Secret confidential content of Studio A',
            'processing_status' => 'ready',
        ]);

        // 4. Request as Tenant B - Ensure ZERO data from Tenant A is returned
        TenantContext::clear();
        $responseSources = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/v1/tenant/sources');

        $responseSources->assertStatus(200);
        $this->assertCount(0, $responseSources->json('resources'));

        $responseVideos = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/v1/tenant/videos');

        $responseVideos->assertStatus(200);
        $this->assertCount(0, $responseVideos->json('resources'));

        // 5. Tenant B attempts direct access to Tenant A's video ID -> Expect 404 (due to global scope)
        $responseDirect = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson("/api/v1/tenant/videos/{$videoA->id}");

        $responseDirect->assertStatus(404);
    }
}
