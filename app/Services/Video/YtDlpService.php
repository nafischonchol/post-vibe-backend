<?php

namespace App\Services\Video;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class YtDlpService
{
    protected ?string $resolvedBinary = null;
    protected ?bool $isAvailableCache = null;

    /**
     * Standard desktop browser User-Agent to prevent anti-bot challenges on TikTok and other platforms.
     */
    public function getUserAgent(): string
    {
        return config('services.yt_dlp.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
    }

    /**
     * Resolve the yt-dlp binary path.
     */
    public function getBinaryPath(): ?string
    {
        if ($this->resolvedBinary !== null) {
            return $this->resolvedBinary;
        }

        $configured = config('services.yt_dlp.binary_path', base_path('bin/yt-dlp'));

        if ($configured && File::isFile($configured) && is_executable($configured)) {
            return $this->resolvedBinary = $configured;
        }

        $localDefault = base_path('bin/yt-dlp');
        if (File::isFile($localDefault) && is_executable($localDefault)) {
            return $this->resolvedBinary = $localDefault;
        }

        // Check if available on system PATH
        try {
            $process = new Process(['which', 'yt-dlp']);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                $path = trim($process->getOutput());
                if (! empty($path) && is_executable($path)) {
                    return $this->resolvedBinary = $path;
                }
            }
        } catch (Exception $e) {
            // Ignore failure looking up PATH
        }

        return null;
    }

    /**
     * Check if yt-dlp is installed and executable.
     */
    public function isAvailable(): bool
    {
        if ($this->isAvailableCache !== null) {
            return $this->isAvailableCache;
        }

        $binary = $this->getBinaryPath();
        if (! $binary) {
            return $this->isAvailableCache = false;
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();

            return $this->isAvailableCache = $process->isSuccessful();
        } catch (Exception $e) {
            Log::debug("YtDlpService availability check error: " . $e->getMessage());
            return $this->isAvailableCache = false;
        }
    }

    /**
     * Get installed yt-dlp version.
     */
    public function getVersion(): ?string
    {
        $binary = $this->getBinaryPath();
        if (! $binary) {
            return null;
        }

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Extract full video metadata via yt-dlp JSON dump.
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
     * }|null
     */
    public function extractMetadata(string $url): ?array
    {
        $binary = $this->getBinaryPath();
        if (! $binary) {
            return null;
        }

        $command = [
            $binary,
            '--user-agent', $this->getUserAgent(),
            '--dump-single-json',
            '--no-warnings',
            '--no-playlist',
            '--skip-download',
            $url,
        ];

        try {
            $timeout = (int) config('services.yt_dlp.timeout', 60);
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning("yt-dlp metadata extraction failed for {$url}: " . $process->getErrorOutput());
                return null;
            }

            $rawJson = trim($process->getOutput());
            $data = json_decode($rawJson, true);

            if (! is_array($data) || empty($data['id'])) {
                return null;
            }

            $caption = $data['description'] ?? $data['title'] ?? '';
            $hashtags = [];
            if (! empty($data['tags']) && is_array($data['tags'])) {
                $hashtags = array_values(array_map('strval', $data['tags']));
            } else {
                preg_match_all('/#(\w+)/u', $caption, $tagMatches);
                $hashtags = array_values(array_unique($tagMatches[1] ?? []));
            }

            $publishedAt = now()->toDateTimeString();
            if (! empty($data['timestamp'])) {
                $publishedAt = date('Y-m-d H:i:s', (int) $data['timestamp']);
            } elseif (! empty($data['upload_date']) && strlen($data['upload_date']) === 8) {
                $publishedAt = substr($data['upload_date'], 0, 4) . '-' .
                    substr($data['upload_date'], 4, 2) . '-' .
                    substr($data['upload_date'], 6, 2) . ' 00:00:00';
            }

            $platform = $this->normalizeExtractor($data['extractor_key'] ?? $data['extractor'] ?? '', $url);

            return [
                'platform' => $platform,
                'source_post_id' => (string) ($data['id'] ?? md5($url)),
                'source_post_url' => $url,
                'title' => ! empty($data['title']) ? Str::limit($data['title'], 90, '...') : null,
                'original_caption' => $caption,
                'original_hashtags' => $hashtags,
                'thumbnail_url' => $data['thumbnail'] ?? null,
                'download_url' => null, // Managed directly by downloadVideo()
                'duration' => isset($data['duration']) ? (int) $data['duration'] : null,
                'author' => $data['uploader'] ?? $data['channel'] ?? $data['uploader_id'] ?? null,
                'source_published_at' => $publishedAt,
            ];
        } catch (Exception $e) {
            Log::error("yt-dlp extractMetadata exception for {$url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Download public video from URL directly to local disk.
     *
     * @param string $url
     * @param string|null $outputPath Optional explicit destination path.
     * @return string Absolute file path to the downloaded video.
     * @throws Exception
     */
    public function downloadVideo(string $url, ?string $outputPath = null): string
    {
        $binary = $this->getBinaryPath();
        if (! $binary) {
            throw new Exception("yt-dlp binary is not installed or available.");
        }

        if (! $outputPath) {
            $tempDir = storage_path('app/temp/videos');
            if (! File::isDirectory($tempDir)) {
                File::makeDirectory($tempDir, 0755, true, true);
            }
            $outputPath = $tempDir . '/' . Str::uuid() . '.mp4';
        } else {
            $parentDir = dirname($outputPath);
            if (! File::isDirectory($parentDir)) {
                File::makeDirectory($parentDir, 0755, true, true);
            }
        }

        $maxSize = config('services.yt_dlp.max_filesize', '150M');
        $timeout = (int) config('services.yt_dlp.timeout', 300);

        // Format selection: prioritize mp4 container, fallback to best standard
        $command = [
            $binary,
            '--user-agent', $this->getUserAgent(),
            '--no-playlist',
            '--no-warnings',
            '-f', 'best[ext=mp4]/best',
            '--max-filesize', $maxSize,
            '-o', $outputPath,
            $url,
        ];

        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new Exception("yt-dlp download failed: " . $process->getErrorOutput());
        }

        // If yt-dlp appended an alternate extension (e.g. .mkv or .webm)
        if (! File::exists($outputPath)) {
            $basePathWithoutExt = preg_replace('/\.[^.]+$/', '', $outputPath);
            $matchingFiles = glob("{$basePathWithoutExt}.*");
            if (! empty($matchingFiles) && File::exists($matchingFiles[0])) {
                return $matchingFiles[0];
            }
            throw new Exception("Downloaded file not found after yt-dlp execution at {$outputPath}");
        }

        return $outputPath;
    }

    /**
     * Extract feed / playlist items from a creator channel or profile URL.
     *
     * @param string $url Channel or profile URL.
     * @param int $limit Maximum number of posts to fetch (default: 5).
     * @return array<int, array{
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
     * }>
     */
    public function extractFeed(string $url, int $limit = 5): array
    {
        $binary = $this->getBinaryPath();
        if (! $binary) {
            return [];
        }

        $command = [
            $binary,
            '--user-agent', $this->getUserAgent(),
            '--flat-playlist',
            '--playlist-end', (string) $limit,
            '--no-warnings',
            '-J',
            $url,
        ];

        try {
            $timeout = (int) config('services.yt_dlp.feed_timeout', 60);
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning("yt-dlp extractFeed failed for {$url}: " . $process->getErrorOutput());
                return [];
            }

            $rawJson = trim($process->getOutput());
            $data = json_decode($rawJson, true);

            if (! is_array($data)) {
                return [];
            }

            $entries = $data['entries'] ?? [];
            if (empty($entries) && ! empty($data['id']) && ($data['_type'] ?? '') !== 'playlist') {
                $entries = [$data];
            }

            $results = [];
            foreach ($entries as $entry) {
                if (empty($entry) || ! is_array($entry)) {
                    continue;
                }

                $postId = (string) ($entry['id'] ?? '');
                if (empty($postId)) {
                    continue;
                }

                // Determine video post URL
                $postUrl = $entry['url'] ?? null;
                if (empty($postUrl) || ! filter_var($postUrl, FILTER_VALIDATE_URL)) {
                    $platform = $this->normalizeExtractor($entry['extractor_key'] ?? $entry['extractor'] ?? $data['extractor_key'] ?? $data['extractor'] ?? '', $url);
                    if ($platform === 'tiktok') {
                        $uploader = $entry['uploader'] ?? $data['uploader'] ?? $entry['channel'] ?? '';
                        $postUrl = ! empty($uploader)
                            ? "https://www.tiktok.com/@" . ltrim($uploader, '@') . "/video/{$postId}"
                            : "https://www.tiktok.com/video/{$postId}";
                    } elseif ($platform === 'youtube') {
                        $postUrl = "https://www.youtube.com/watch?v={$postId}";
                    } else {
                        $postUrl = $entry['webpage_url'] ?? $url;
                    }
                }

                $caption = $entry['description'] ?? $entry['title'] ?? '';
                $title = ! empty($entry['title']) ? Str::limit($entry['title'], 90, '...') : null;

                $hashtags = [];
                if (! empty($entry['tags']) && is_array($entry['tags'])) {
                    $hashtags = array_values(array_map('strval', $entry['tags']));
                } else {
                    preg_match_all('/#(\w+)/u', $caption, $tagMatches);
                    $hashtags = array_values(array_unique($tagMatches[1] ?? []));
                }

                // Best thumbnail
                $thumbnailUrl = null;
                if (! empty($entry['thumbnails']) && is_array($entry['thumbnails'])) {
                    $lastThumb = end($entry['thumbnails']);
                    $thumbnailUrl = is_array($lastThumb) ? ($lastThumb['url'] ?? null) : null;
                } elseif (! empty($entry['thumbnail'])) {
                    $thumbnailUrl = $entry['thumbnail'];
                }

                // Published timestamp
                $publishedAt = now()->toDateTimeString();
                if (! empty($entry['timestamp'])) {
                    $publishedAt = date('Y-m-d H:i:s', (int) $entry['timestamp']);
                } elseif (! empty($entry['upload_date']) && strlen($entry['upload_date']) === 8) {
                    $publishedAt = substr($entry['upload_date'], 0, 4) . '-' .
                        substr($entry['upload_date'], 4, 2) . '-' .
                        substr($entry['upload_date'], 6, 2) . ' 00:00:00';
                }

                $duration = isset($entry['duration']) && $entry['duration'] !== null ? (int) $entry['duration'] : null;

                // Skip non-video posts (e.g. TikTok photo mode) and videos shorter than 5 seconds
                if ($duration === null || $duration < 5) {
                    continue;
                }

                $results[] = [
                    'source_post_id' => $postId,
                    'source_post_url' => $postUrl,
                    'title' => $title,
                    'original_caption' => $caption,
                    'original_hashtags' => $hashtags,
                    'thumbnail_url' => $thumbnailUrl,
                    'download_url' => null, // Resolved in downloadVideo()
                    'duration' => $duration,
                    'author' => $entry['uploader'] ?? $entry['channel'] ?? $data['uploader'] ?? null,
                    'source_published_at' => $publishedAt,
                ];
            }

            return $results;
        } catch (Exception $e) {
            Log::error("yt-dlp extractFeed exception for {$url}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalize platform extractor key to app standard platforms.
     */
    public function normalizeExtractor(string $extractor, string $url): string
    {
        $extractorLower = strtolower($extractor);
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (str_contains($extractorLower, 'youtube') || str_contains($host, 'youtube') || str_contains($host, 'youtu.be')) {
            return 'youtube';
        }

        if (str_contains($extractorLower, 'tiktok') || str_contains($host, 'tiktok')) {
            return 'tiktok';
        }

        if (str_contains($extractorLower, 'instagram') || str_contains($host, 'instagram')) {
            return 'instagram';
        }

        if (str_contains($extractorLower, 'facebook') || str_contains($host, 'facebook') || str_contains($host, 'fb.watch')) {
            return 'facebook';
        }

        return 'generic';
    }
}
