<?php

namespace App\Services\Video;

use App\Jobs\Video\DownloadAndStoreVideoJob;
use App\Models\Video;
use App\Models\VideoSource;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoIngestionService
{
    public function __construct(
        protected VideoScraperService $scraper,
        protected ?YtDlpService $ytDlp = null
    ) {
        $this->ytDlp = $ytDlp ?? app(YtDlpService::class);
    }

    /**
     * Ingest and scrape a video from a direct URL.
     */
    public function ingestFromUrl(int $tenantId, string $url, ?int $sourceId = null): Video
    {
        $targetUrl = $url;

        // If a channel or profile URL was provided, attempt to resolve the most recent video post
        if (! $this->isDirectVideoUrl($url) && $this->ytDlp && $this->ytDlp->isAvailable()) {
            $feed = $this->ytDlp->extractFeed($url, 1);
            if (! empty($feed[0]['source_post_url'])) {
                $targetUrl = $feed[0]['source_post_url'];
            }
        }

        $scraped = $this->scraper->scrape($targetUrl);

        // Reject if duration is known and less than 5 seconds
        $duration = isset($scraped['duration']) && $scraped['duration'] !== null ? (int) $scraped['duration'] : null;
        if ($duration !== null && $duration < 5) {
            throw new Exception("Video length is {$duration}s, which is less than the required minimum of 5 seconds.");
        }
        if ($duration === null) {
            $duration = 30;
            $scraped['duration'] = 30;
        }

        // 1. Check duplicate within tenant
        $existing = Video::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_post_id', $scraped['source_post_id'])
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Create Video Record
        $video = Video::create([
            'tenant_id' => $tenantId,
            'source_id' => $sourceId,
            'source_platform' => $scraped['platform'],
            'source_post_id' => $scraped['source_post_id'],
            'source_post_url' => $scraped['source_post_url'],
            'original_caption' => $scraped['original_caption'],
            'original_hashtags' => $scraped['original_hashtags'],
            'thumbnail_url' => $scraped['thumbnail_url'],
            'duration' => $scraped['duration'],
            'source_published_at' => $scraped['source_published_at'],
            'processing_status' => 'discovered',
        ]);

        // 3. Dispatch download & processing
        $mediaUrl = $scraped['download_url'] ?? $scraped['source_post_url'];
        if ($mediaUrl) {
            DownloadAndStoreVideoJob::dispatch($video, $mediaUrl);
        }

        return $video;
    }

    /**
     * Ingest a discovered video record and dispatch download/processing.
     * Skips videos published more than $maxAgeHours (default: 168 / 7 days) ago.
     */
    public function ingestDiscoveredVideo(VideoSource $source, array $postData, int $maxAgeHours = 168): ?Video
    {
        // 1. Skip if video duration is unknown or less than 5 seconds
        $duration = isset($postData['duration']) && $postData['duration'] !== null ? (int) $postData['duration'] : null;
        if ($duration === null || $duration < 5) {
            Log::info("Skipping post {$postData['source_post_id']} for source {$source->name}: duration is " . ($duration !== null ? "{$duration}s" : 'unknown/0s') . " (minimum 5s required).");
            return null;
        }

        // 2. Skip if video is older than $maxAgeHours (168 hours)
        $publishedAt = $postData['source_published_at'] ?? null;
        if ($publishedAt && $maxAgeHours > 0) {
            try {
                $publishedTime = \Carbon\Carbon::parse($publishedAt);
                if ($publishedTime->isBefore(now()->subHours($maxAgeHours))) {
                    Log::info("Skipping video {$postData['source_post_id']} for source {$source->name}: published at {$publishedAt} which is older than {$maxAgeHours} hours.");
                    return null;
                }
            } catch (Exception $e) {
                Log::warning("Failed to parse published date '{$publishedAt}': " . $e->getMessage());
            }
        }

        // 2. Check for Duplicate by Source Post ID within Tenant
        $existing = Video::withoutGlobalScopes()
            ->where('tenant_id', $source->tenant_id)
            ->where('source_id', $source->id)
            ->where('source_post_id', $postData['source_post_id'])
            ->first();

        if ($existing) {
            return $existing;
        }

        // 3. Extract hashtags from caption if not provided
        $caption = $postData['original_caption'] ?? '';
        $hashtags = $postData['original_hashtags'] ?? $this->scraper->extractHashtags($caption);

        // 4. Create Video Record
        $video = Video::create([
            'tenant_id' => $source->tenant_id,
            'source_id' => $source->id,
            'source_platform' => $source->platform,
            'source_post_id' => $postData['source_post_id'],
            'source_post_url' => $postData['source_post_url'] ?? null,
            'original_caption' => $caption,
            'original_hashtags' => array_values($hashtags),
            'thumbnail_url' => $postData['thumbnail_url'] ?? null,
            'duration' => $postData['duration'] ?? null,
            'source_published_at' => $publishedAt ?? now(),
            'processing_status' => 'discovered',
        ]);

        // 5. Dispatch Download and S3 Processing Job
        $mediaUrl = $postData['download_url'] ?? $postData['source_post_url'] ?? null;
        if ($mediaUrl) {
            DownloadAndStoreVideoJob::dispatch($video, $mediaUrl);
        }

        return $video;
    }

    /**
     * Reprocess an existing video.
     */
    public function reprocessVideo(Video $video, ?string $mediaUrl = null): Video
    {
        $video->update([
            'processing_status' => 'discovered',
            'processing_error' => null,
        ]);

        $url = $mediaUrl ?? $video->source_post_url;
        if ($url) {
            DownloadAndStoreVideoJob::dispatch($video, $url);
        }

        return $video;
    }

    /**
     * Check if a URL targets a specific video/short/reel rather than a channel/profile page.
     */
    public function isDirectVideoUrl(?string $url): bool
    {
        if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';
        $query = $parsed['query'] ?? '';

        // TikTok direct video
        if (str_contains($host, 'tiktok.com')) {
            if (preg_match('#/video/\d+#', $path) || preg_match('#/t/[A-Za-z0-9]+#', $path)) {
                return true;
            }
        }
        if (str_contains($host, 'vm.tiktok.com') || str_contains($host, 'vt.tiktok.com')) {
            return true;
        }

        // YouTube direct video/short
        if (str_contains($host, 'youtu.be')) {
            return true;
        }
        if (str_contains($host, 'youtube.com')) {
            if (str_contains($path, '/shorts/') || str_contains($path, '/watch') || str_contains($query, 'v=')) {
                return true;
            }
        }

        // Instagram direct post/reel
        if (str_contains($host, 'instagram.com') || str_contains($host, 'instagr.am')) {
            if (preg_match('#/(reel|reels|p|tv)/[^/]+#', $path) || str_contains($path, '/share/reel/')) {
                return true;
            }
        }

        // Facebook direct video / reel / share
        if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.watch')) {
            if (str_contains($host, 'fb.watch')) {
                return true;
            }
            if (str_contains($path, '/videos/') ||
                str_contains($path, '/reel/') ||
                str_contains($path, '/reels/') ||
                str_contains($path, '/watch') ||
                str_contains($path, '/share/r/') ||
                str_contains($path, '/share/v/') ||
                str_contains($path, '/share/p/') ||
                preg_match('#/posts/[^/]+#', $path) ||
                str_contains($query, 'v=')) {
                return true;
            }
        }

        // Direct media files
        if (preg_match('/\.(mp4|mov|webm)$/i', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Fetch feed items for a given video source.
     */
    public function fetchSourceFeed(VideoSource $source): array
    {
        // 1. If source_url points to a specific video/short, scrape it directly
        if (! empty($source->source_url) && $this->isDirectVideoUrl($source->source_url)) {
            try {
                $scraped = $this->scraper->scrape($source->source_url);
                return [
                    [
                        'source_post_id' => $scraped['source_post_id'],
                        'source_post_url' => $scraped['source_post_url'],
                        'original_caption' => $scraped['original_caption'],
                        'original_hashtags' => $scraped['original_hashtags'],
                        'thumbnail_url' => $scraped['thumbnail_url'],
                        'duration' => $scraped['duration'],
                        'source_published_at' => $scraped['source_published_at'],
                        'download_url' => $scraped['download_url'],
                    ]
                ];
            } catch (\Exception $e) {
                Log::warning("Direct scraper failed on {$source->source_url}: " . $e->getMessage());
            }
        }

        // 2. Resolve target profile/channel URL for feed extraction
        $targetUrl = $source->source_url;
        if (empty($targetUrl) && ! empty($source->source_identifier)) {
            $identifier = ltrim($source->source_identifier, '@');
            $targetUrl = match ($source->platform) {
                'tiktok' => "https://www.tiktok.com/@{$identifier}",
                'youtube' => "https://www.youtube.com/@{$identifier}",
                'instagram' => "https://www.instagram.com/{$identifier}",
                'facebook' => "https://www.facebook.com/{$identifier}",
                default => null,
            };
        }

        // 3. Extract real video posts from channel/profile feed via yt-dlp
        if (! empty($targetUrl) && $this->ytDlp && $this->ytDlp->isAvailable()) {
            try {
                $feed = $this->ytDlp->extractFeed($targetUrl, 5);
                if (! empty($feed)) {
                    return $feed;
                }
            } catch (\Exception $e) {
                Log::warning("yt-dlp extractFeed failed for source [{$source->name}] {$targetUrl}: " . $e->getMessage());
            }
        }

        Log::info("No videos found or feed extraction unavailable for source ID {$source->id} ({$source->name}).");
        return [];
    }

    /**
     * Scan a source immediately and ingest any discovered videos.
     * Skips videos published more than $maxAgeHours (default: 168 / 7 days) ago.
     */
    public function scanSource(VideoSource $source, int $maxAgeHours = 168): array
    {
        $discoveredPosts = $this->fetchSourceFeed($source);

        $ingested = [];
        $skippedCount = 0;

        foreach ($discoveredPosts as $post) {
            $video = $this->ingestDiscoveredVideo($source, $post, $maxAgeHours);
            if ($video && $video->wasRecentlyCreated) {
                $ingested[] = $video;
            } elseif ($video === null) {
                $skippedCount++;
            }
        }

        $source->update([
            'last_scanned_at' => now(),
            'last_error' => null,
        ]);

        return [
            'ingested' => $ingested,
            'skipped_count' => $skippedCount,
        ];
    }
}
