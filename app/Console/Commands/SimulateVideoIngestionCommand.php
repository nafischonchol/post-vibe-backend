<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Video;
use App\Models\VideoSource;
use App\Services\AI\PlatformMetadataService;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SimulateVideoIngestionCommand extends Command
{
    protected $signature = 'video:simulate-ingest {tenant_id=1}';
    protected $description = 'Simulate video discovery, ingestion, and AI metadata generation for testing';

    public function handle(PlatformMetadataService $metadataService): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $tenant = Tenant::findOrFail($tenantId);
        TenantContext::setTenant($tenant);

        $this->info("Simulating video ingestion for workspace: {$tenant->name} (ID: {$tenant->id})");

        // 1. Ensure a VideoSource exists
        $source = VideoSource::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'platform' => 'youtube',
                'source_identifier' => '@TechQuickies',
            ],
            [
                'source_url' => 'https://youtube.com/@TechQuickies',
                'name' => 'Tech Quickies Shorts',
                'scan_frequency' => 30,
                'status' => 'active',
            ]
        );

        $this->info("Using VideoSource: {$source->name} (ID: {$source->id})");

        // 2. Create Video
        $samplePostId = 'post_' . substr(md5(uniqid()), 0, 10);
        $sampleCaptions = [
            'Top 3 AI Tools that will completely change how you edit vertical short videos in 2026! #ai #tech #videoediting #shorts',
            'Why you should NEVER charge your phone to 100% overnight explained in 45 seconds. #battery #technology #gadgets',
            'The secret feature in modern web browsers that saves 50% of your RAM usage! #techtips #productivity #coding',
        ];
        $selectedCaption = $sampleCaptions[array_rand($sampleCaptions)];

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $selectedCaption, $matches);
        $hashtags = array_unique($matches[1] ?? []);

        $video = Video::create([
            'tenant_id' => $tenant->id,
            'source_id' => $source->id,
            'source_platform' => $source->platform,
            'source_post_id' => $samplePostId,
            'source_post_url' => "https://youtube.com/shorts/{$samplePostId}",
            'video_hash' => hash('sha256', $samplePostId . time()),
            'original_caption' => $selectedCaption,
            'original_hashtags' => array_values($hashtags),
            'duration' => rand(25, 58),
            'thumbnail_url' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
            'temporary_storage_path' => "tenants/{$tenant->id}/videos/{$samplePostId}.mp4",
            'processing_status' => 'stored',
        ]);

        $this->info("Created Video record: ID #{$video->id} with status 'stored'");

        // 3. Generate Platform Metadata (Batched prompt)
        $this->info("Generating batched multi-platform AI metadata (YouTube + TikTok + Facebook)...");

        try {
            $metadata = $metadataService->generateBatchedMetadata($video);
            $this->info("AI Metadata successfully generated for platforms: " . implode(', ', array_keys($metadata)));
        } catch (\Exception $e) {
            $this->warn("AI Generation fallback triggered: " . $e->getMessage());
        }

        $video->update(['processing_status' => 'ready']);
        $this->info("Video #{$video->id} is now 'ready' for Together Publishing syndication!");

        return Command::SUCCESS;
    }
}
