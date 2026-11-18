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
