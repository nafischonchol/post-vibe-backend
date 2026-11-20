<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Video;
use App\Models\VideoSource;
use App\Services\Video\VideoScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VideoScraperAndIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected TenantUser $tenantUser;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Syndicate Studios',
            'slug' => 'syndicate-studios',
            'email' => 'sarah@syndicate.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->tenantUser = TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sarah Creator',
            'email' => 'sarah@syndicate.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->tenantUser->createToken('tenant-auth', ['role:tenant'])->plainTextToken;
    }

    public function test_video_scraper_detects_platforms_and_extracts_hashtags(): void
    {
        $scraper = app(VideoScraperService::class);

        $this->assertEquals('youtube', $scraper->detectPlatform('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertEquals('tiktok', $scraper->detectPlatform('https://www.tiktok.com/@user/video/71234567890'));
        $this->assertEquals('instagram', $scraper->detectPlatform('https://www.instagram.com/reel/C8xyz123/'));
        $this->assertEquals('facebook', $scraper->detectPlatform('https://www.facebook.com/reel/987654321'));

        $hashtags = $scraper->extractHashtags('Amazing productivity lifehack #productivity #workflow #lifehack');
        $this->assertCount(3, $hashtags);
        $this->assertContains('productivity', $hashtags);
        $this->assertContains('workflow', $hashtags);
        $this->assertContains('lifehack', $hashtags);
    }

    public function test_tenant_can_scrape_and_ingest_video_via_api(): void
    {
        $sampleUrl = 'https://www.youtube.com/shorts/dQw4w9WgXcQ';

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/tenant/sources/scrape-video', [
                'url' => $sampleUrl,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('videos', [
            'tenant_id' => $this->tenant->id,
            'source_platform' => 'youtube',
            'source_post_url' => $sampleUrl,
        ]);
    }

    public function test_scraper_prevents_duplicate_video_ingestion(): void
    {
        $sampleUrl = 'https://www.youtube.com/shorts/dQw4w9WgXcQ';

        // Ingest once
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/tenant/sources/scrape-video', ['url' => $sampleUrl]);

        // Ingest duplicate
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/tenant/sources/scrape-video', ['url' => $sampleUrl]);

        $response->assertStatus(201);

        // Ensure only 1 record exists in DB
        $count = Video::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('source_platform', 'youtube')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_tenant_can_trigger_instant_source_scrape(): void
    {
        $source = VideoSource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tech Shorts Channel',
            'platform' => 'youtube',
            'source_identifier' => '@techshorts',
            'source_url' => 'https://www.youtube.com/@techshorts',
            'scan_frequency' => 60,
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/tenant/sources/{$source->id}/scan");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $source->refresh();
        $this->assertNotNull($source->last_scanned_at);
        $this->assertDatabaseHas('videos', [
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
        ]);
    }
}
