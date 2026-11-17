<?php

namespace App\Services\Video;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoScraperService
{
    protected int $timeout = 25;

    /**
     * Scrape video metadata and direct media stream from any supported social URL.
     *
     * @param string $url
     * @return array{
     *     platform: string,
     *     source_post_id: string,
     *     source_post_url: string,
     *     title: ?string,
     *     original_caption: string,
     *     original_hashtags: array<string>,
     *     thumbnail_url: ?string,
     *     download_url: ?string,
     *     duration: ?int,
     *     author: ?string,
     *     source_published_at: string
     * }
     */
    public function scrape(string $url): array
    {
        $cleanUrl = trim($url);
        $platform = $this->detectPlatform($cleanUrl);

        try {
            return match ($platform) {
                'tiktok' => $this->scrapeTikTok($cleanUrl),
                'youtube' => $this->scrapeYouTube($cleanUrl),
                'instagram' => $this->scrapeInstagram($cleanUrl),
                'facebook' => $this->scrapeFacebook($cleanUrl),
                default => $this->scrapeGeneric($cleanUrl),
            };
        } catch (Exception $e) {
            Log::warning("Primary scraper failed for [{$platform}] {$cleanUrl}: " . $e->getMessage() . " - Falling back to generic extraction.");
            return $this->scrapeGeneric($cleanUrl, $platform);
        }
    }

    /**
     * Detect platform from URL.
     */
    public function detectPlatform(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (str_contains($host, 'tiktok.com')) return 'tiktok';
        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) return 'youtube';
        if (str_contains($host, 'instagram.com') || str_contains($host, 'instagr.am')) return 'instagram';
        if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.watch')) return 'facebook';

        return 'generic';
    }

    /**
     * Scrape TikTok video using TikWM and official oEmbed.
     */
    protected function scrapeTikTok(string $url): array
    {
        // 1. Try high-performance stream extractor (TikWM)
        try {
            $response = Http::timeout($this->timeout)->get('https://www.tikwm.com/api/', [
                'url' => $url,
                'hd' => 1,
            ]);

            if ($response->successful() && $response->json('code') === 0) {
                $data = $response->json('data');
                $caption = $data['title'] ?? '';
                $hashtags = $this->extractHashtags($caption);

                $streamUrl = $data['play'] ?? $data['wmplay'] ?? null;
                if ($streamUrl && str_starts_with($streamUrl, '/')) {
                    $streamUrl = 'https://www.tikwm.com' . $streamUrl;
                }

                return [
                    'platform' => 'tiktok',
                    'source_post_id' => (string) ($data['id'] ?? md5($url)),
                    'source_post_url' => $url,
                    'title' => Str::limit($caption, 90, '...'),
                    'original_caption' => $caption,
                    'original_hashtags' => $hashtags,
                    'thumbnail_url' => $data['cover'] ?? $data['origin_cover'] ?? null,
                    'download_url' => $streamUrl,
                    'duration' => (int) ($data['duration'] ?? 30),
                    'author' => $data['author']['unique_id'] ?? $data['author']['nickname'] ?? null,
                    'source_published_at' => isset($data['create_time']) ? date('Y-m-d H:i:s', $data['create_time']) : now()->toDateTimeString(),
                ];
            }
        } catch (Exception $e) {
            Log::debug("TikWM extractor fallback for {$url}: " . $e->getMessage());
        }

        // 2. Fallback to official TikTok oEmbed
        $oembed = Http::timeout($this->timeout)->get('https://www.tiktok.com/oembed', ['url' => $url]);
        if ($oembed->successful()) {
            $data = $oembed->json();
            $caption = $data['title'] ?? '';
            return [
                'platform' => 'tiktok',
                'source_post_id' => 'tt_' . substr(md5($url), 0, 12),
                'source_post_url' => $url,
                'title' => Str::limit($caption, 90, '...'),
                'original_caption' => $caption,
                'original_hashtags' => $this->extractHashtags($caption),
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'download_url' => null, // Resolved during ingestion/download job
                'duration' => 30,
                'author' => $data['author_name'] ?? null,
                'source_published_at' => now()->toDateTimeString(),
            ];
        }

        return $this->scrapeGeneric($url, 'tiktok');
    }

    /**
     * Scrape YouTube Shorts / Video using oEmbed and page metadata.
     */
    protected function scrapeYouTube(string $url): array
    {
        $videoId = $this->extractYouTubeId($url);

        // Fetch oEmbed for official title and author
        $oembed = Http::timeout($this->timeout)->get('https://www.youtube.com/oembed', [
            'url' => "https://www.youtube.com/watch?v={$videoId}",
            'format' => 'json',
        ]);

        $title = null;
        $author = null;
        $thumbnail = "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg";

        if ($oembed->successful()) {
            $data = $oembed->json();
            $title = $data['title'] ?? null;
            $author = $data['author_name'] ?? null;
            $thumbnail = $data['thumbnail_url'] ?? $thumbnail;
        }

        $caption = $title ?: 'YouTube Short Video';

        return [
            'platform' => 'youtube',
            'source_post_id' => $videoId ?: 'yt_' . substr(md5($url), 0, 11),
            'source_post_url' => $url,
            'title' => $title,
            'original_caption' => $caption,
            'original_hashtags' => $this->extractHashtags($caption),
            'thumbnail_url' => $thumbnail,
            'download_url' => null, // Resolved in download pipeline
            'duration' => 45,
            'author' => $author,
            'source_published_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Scrape Instagram Reel metadata via OpenGraph HTML tags.
     */
    protected function scrapeInstagram(string $url): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml',
        ])->timeout($this->timeout)->get($url);

        $html = $response->body();

        // Extract OpenGraph tags
        $videoUrl = $this->matchMetaContent($html, 'og:video') ?: $this->matchMetaContent($html, 'og:video:secure_url');
        $title = $this->matchMetaContent($html, 'og:title');
        $description = $this->matchMetaContent($html, 'og:description');
        $image = $this->matchMetaContent($html, 'og:image');

        $caption = $description ?: $title ?: 'Instagram Reel';
        preg_match('/instagram\.com\/(?:reel|p)\/([A-Za-z0-9_-]+)/', $url, $matches);
        $postId = $matches[1] ?? 'ig_' . substr(md5($url), 0, 11);

        return [
            'platform' => 'instagram',
            'source_post_id' => $postId,
            'source_post_url' => $url,
            'title' => $title ? Str::limit($title, 90, '...') : null,
            'original_caption' => $caption,
            'original_hashtags' => $this->extractHashtags($caption),
            'thumbnail_url' => $image,
            'download_url' => $videoUrl,
            'duration' => 30,
            'author' => null,
            'source_published_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Scrape Facebook Reel metadata via OpenGraph tags.
     */
    protected function scrapeFacebook(string $url): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ])->timeout($this->timeout)->get($url);

        $html = $response->body();
        $videoUrl = $this->matchMetaContent($html, 'og:video') ?: $this->matchMetaContent($html, 'og:video:secure_url');
        $title = $this->matchMetaContent($html, 'og:title') ?: 'Facebook Reel';
        $image = $this->matchMetaContent($html, 'og:image');

        return [
            'platform' => 'facebook',
            'source_post_id' => 'fb_' . substr(md5($url), 0, 12),
            'source_post_url' => $url,
            'title' => Str::limit($title, 90, '...'),
            'original_caption' => $title,
            'original_hashtags' => $this->extractHashtags($title),
            'thumbnail_url' => $image,
            'download_url' => $videoUrl,
            'duration' => 35,
            'author' => null,
            'source_published_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Generic OpenGraph and direct video metadata scraper.
     */
    protected function scrapeGeneric(string $url, ?string $forcedPlatform = null): array
    {
        $platform = $forcedPlatform ?: $this->detectPlatform($url);

        // Check if direct video file
        $cleanPath = parse_url($url, PHP_URL_PATH) ?? '';
        if (preg_match('/\.(mp4|mov|webm)$/i', $cleanPath)) {
            $filename = pathinfo($cleanPath, PATHINFO_FILENAME);
            $caption = ucwords(str_replace(['-', '_'], ' ', $filename));

            return [
                'platform' => $platform,
                'source_post_id' => 'vid_' . substr(md5($url), 0, 10),
                'source_post_url' => $url,
                'title' => $caption,
                'original_caption' => $caption,
                'original_hashtags' => $this->extractHashtags($caption),
                'thumbnail_url' => null,
                'download_url' => $url,
                'duration' => 30,
                'author' => null,
                'source_published_at' => now()->toDateTimeString(),
            ];
        }

        // Fetch HTML and inspect OpenGraph
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])->timeout($this->timeout)->get($url);

            $html = $res->body();
            $title = $this->matchMetaContent($html, 'og:title') ?: $this->matchTitleTag($html) ?: 'Short Video';
            $description = $this->matchMetaContent($html, 'og:description');
            $image = $this->matchMetaContent($html, 'og:image');
            $video = $this->matchMetaContent($html, 'og:video') ?: $this->matchMetaContent($html, 'og:video:secure_url');

            $caption = $description ?: $title;

            return [
                'platform' => $platform,
                'source_post_id' => 'post_' . substr(md5($url), 0, 10),
                'source_post_url' => $url,
                'title' => Str::limit($title, 90, '...'),
                'original_caption' => $caption,
                'original_hashtags' => $this->extractHashtags($caption),
                'thumbnail_url' => $image,
                'download_url' => $video,
                'duration' => 30,
                'author' => null,
                'source_published_at' => now()->toDateTimeString(),
            ];
        } catch (Exception $e) {
            return [
                'platform' => $platform,
                'source_post_id' => 'post_' . substr(md5($url), 0, 10),
                'source_post_url' => $url,
                'title' => 'Ingested Short Video',
                'original_caption' => 'Ingested Short Video from ' . $url,
                'original_hashtags' => ['shorts', 'trending'],
                'thumbnail_url' => null,
                'download_url' => null,
                'duration' => 30,
                'author' => null,
                'source_published_at' => now()->toDateTimeString(),
            ];
        }
    }

    /**
     * Extract hashtags from text.
     */
    public function extractHashtags(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $text, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    protected function extractYouTubeId(string $url): string
    {
        if (preg_match('/(?:shorts\/|v=|\/v\/|youtu\.be\/|\/embed\/)([A-Za-z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return substr(md5($url), 0, 11);
    }

    protected function matchMetaContent(string $html, string $property): ?string
    {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }

        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }

        return null;
    }

    protected function matchTitleTag(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }

        return null;
    }
}
