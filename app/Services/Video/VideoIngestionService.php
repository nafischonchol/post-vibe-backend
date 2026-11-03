<?php

namespace App\Services\Video;

use App\Jobs\Video\DownloadAndStoreVideoJob;
use App\Models\Video;
use App\Models\VideoSource;
use Illuminate\Support\Str;

class VideoIngestionService
{
    /**
     * Ingest a discovered video record and dispatch download/processing.
     */
    public function ingestDiscoveredVideo(VideoSource $source, array $postData): ?Video
    {
        // 1. Check for Duplicate by Source Post ID within Tenant
        $existing = Video::withoutGlobalScopes()
            ->where('tenant_id', $source->tenant_id)
            ->where('source_id', $source->id)
            ->where('source_post_id', $postData['source_post_id'])
            ->first();

        if ($existing) {
            return $existing;
        }

        // 2. Extract hashtags from caption
        $caption = $postData['original_caption'] ?? '';
        $hashtags = [];
        if (! empty($caption)) {
            preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);
            $hashtags = array_unique($matches[1] ?? []);
        }

        // 3. Create Video Record
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
            'source_published_at' => $postData['source_published_at'] ?? now(),
            'processing_status' => 'discovered',
        ]);

        // 4. Dispatch Download and S3 Processing Job
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
}
