<?php

namespace App\Console\Commands;

use App\Models\VideoSource;
use App\Services\Video\VideoIngestionService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScanVideoSourcesCommand extends Command
{
    protected $signature = 'sources:scan {--source_id=} {--limit=10}';
    protected $description = 'Scan active video sources and ingest newly discovered videos';

    public function handle(VideoIngestionService $ingestionService): int
    {
        $query = VideoSource::where('status', 'active');

        if ($sourceId = $this->option('source_id')) {
            $query->where('id', $sourceId);
        } else {
            // Find sources due for scanning based on scan_frequency
            $query->where(function ($q) {
                $q->whereNull('last_scanned_at')
                  ->orWhereRaw('TIMESTAMPDIFF(MINUTE, last_scanned_at, NOW()) >= scan_frequency');
            });
        }

        $sources = $query->limit((int) $this->option('limit'))->get();

        if ($sources->isEmpty()) {
            $this->info("No video sources due for scanning.");
            return Command::SUCCESS;
        }

        $this->info("Scanning {$sources->count()} video sources for new media...");

        foreach ($sources as $source) {
            try {
                $this->info("Scanning [{$source->platform}] {$source->name} ({$source->source_identifier})...");

                // Collect new videos from source platform
                $discoveredPosts = $this->fetchSourceFeed($source);

                $ingestedCount = 0;
                foreach ($discoveredPosts as $post) {
                    $video = $ingestionService->ingestDiscoveredVideo($source, $post);
                    if ($video && $video->wasRecentlyCreated) {
                        $ingestedCount++;
                    }
                }

                $source->update([
                    'last_scanned_at' => now(),
                ]);

                $this->info("Ingested {$ingestedCount} new video(s) for {$source->name}.");

            } catch (Exception $e) {
                Log::error("Failed to scan source ID {$source->id}: " . $e->getMessage());
                $this->error("Error scanning source ID {$source->id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Fetch feed items using supported platform APIs or compliant extractors.
     */
    protected function fetchSourceFeed(VideoSource $source): array
    {
        // For development/mock/compliance, generate realistic short-form video discovery records:
        $sampleCaptions = [
            "Quick productivity tip that will save you 10 hours this week! #shorts #productivity #lifehacks",
            "This hidden browser feature is actually insane. Here's how to turn it on. #tech #tips #software",
            "Why most creators fail at vertical video in 2026. Avoid this 1 mistake. #contentcreation #reels #growth",
        ];

        $postId = 'post_' . substr(md5($source->source_identifier . '_' . date('Y-m-d_H')), 0, 10);
        $caption = $sampleCaptions[crc32($postId) % count($sampleCaptions)];

        return [
            [
                'source_post_id' => $postId,
                'source_post_url' => "https://{$source->platform}.com/watch/{$postId}",
                'original_caption' => $caption,
                'thumbnail_url' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
                'duration' => 38,
                'source_published_at' => now()->subHours(1),
                'download_url' => null, // Local simulator avoids remote binary fetch if not provided
            ]
        ];
    }
}
