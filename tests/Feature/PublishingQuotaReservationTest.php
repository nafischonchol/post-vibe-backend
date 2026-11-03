<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPlatformAccount;
use App\Models\TenantUser;
use App\Models\Video;
use App\Models\VideoSource;
use App\Services\Publishing\PublishingQuotaService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublishingQuotaReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\SystemSettingSeeder::class);
    }

    public function test_batch_creation_atomically_reserves_quota_and_cancelling_releases_it(): void
    {
        // 1. Setup Tenant and Pro subscription
        $tenant = Tenant::create([
            'name' => 'Syndicate Studio',
            'slug' => 'syndicate-studio',
            'email' => 'sync@syndicate.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $plan = Plan::where('slug', 'pro')->first();
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => 'Syndicate Owner',
            'email' => 'owner@syndicate.com',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $token = $user->createToken('tenant-auth', ['role:tenant'])->plainTextToken;

        TenantContext::setTenant($tenant);

        // 2. Setup Destination Account & Video
        $account = TenantPlatformAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => 'youtube',
            'account_name' => 'Syndicate YouTube',
            'account_handle' => '@syndicate',
            'encrypted_access_token' => 'mock_token',
            'status' => 'active',
            'token_expires_at' => now()->addDays(30),
        ]);

        $video = Video::create([
            'tenant_id' => $tenant->id,
            'source_platform' => 'tiktok',
            'source_post_id' => 'post_sync_99',
            'original_caption' => 'Exciting tech launch reel',
            'processing_status' => 'ready',
        ]);

        // 3. Create Publishing Batch via API
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/tenant/publishing/batches', [
                'video_id' => $video->id,
                'targets' => [
                    ['platform' => 'youtube', 'account_id' => $account->id],
                ],
            ]);

        $response->assertStatus(201);
        $batchId = $response->json('resources.id');

        // Verify Quota was Reserved
        $quotaService = app(PublishingQuotaService::class);
        $today = $quotaService->getTenantQuotaDate($tenant);
        $globalUsage = \App\Models\TenantDailyQuotaUsage::where('tenant_id', $tenant->id)->whereDate('quota_date', $today)->first();

        $this->assertNotNull($globalUsage);
        $this->assertEquals(1, $globalUsage->reserved_count);

        // 4. Cancel the batch
        $cancelResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/tenant/publishing/batches/{$batchId}/cancel");

        $cancelResponse->assertStatus(200);

        // Verify Quota was released
        $globalUsage->refresh();
        $this->assertEquals(0, $globalUsage->reserved_count);
    }
}
