<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JinaReaderService
{
    protected string $readerBaseUrl = 'https://r.jina.ai';
    protected int $timeout = 35;

    /**
     * Fetch clean markdown from any URL using r.jina.ai.
     *
     * @param string $url
     * @return array{title: ?string, markdown: string, featured_image: ?string, links: array<string>}
     */
    public function fetch(string $url): array
    {
        $cleanUrl = trim($url);
        $jinaUrl = rtrim($this->readerBaseUrl, '/') . '/' . $cleanUrl;

        // Try to resolve authoritative og:image directly from the target page first
        $ogImage = $this->extractOpenGraphImage($cleanUrl);

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/markdown',
                'User-Agent' => 'Mozilla/5.0 (compatible; MohimaBot/1.0; +https://mohima.com)',
                'X-Return-Format' => 'markdown',
            ])->timeout($this->timeout)->retry(2, 1000)->get($jinaUrl);

            if (! $response->successful()) {
                Log::warning("Jina Reader request failed with status [{$response->status()}] for URL: {$cleanUrl}");
                throw new \RuntimeException("Failed to fetch content from URL via Jina Reader (Status {$response->status()})");
            }

            $rawContent = $response->body();
            $parsed = $this->parseJinaOutput($rawContent, $cleanUrl);

            // If og:image was found from the source page, it takes top precedence over markdown images
            if ($ogImage) {
                $parsed['featured_image'] = $ogImage;
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::error("Jina Reader error on {$cleanUrl}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract og:image or twitter:image directly from the page HTML.
     */
    public function extractOpenGraphImage(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(8)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Match property="og:image" content="..."
            if (preg_match('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image(?::src)?)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $candidate = trim($m[1]);
                if (! empty($candidate) && ! $this->isLogoOrAd($candidate)) {
                    return $this->resolveAbsoluteUrl($candidate, $url);
                }
            }

            // Match reverse attribute order: content="..." property="og:image"
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image|twitter:image(?::src)?)["\']/i', $html, $m)) {
                $candidate = trim($m[1]);
                if (! empty($candidate) && ! $this->isLogoOrAd($candidate)) {
                    return $this->resolveAbsoluteUrl($candidate, $url);
                }
            }
        } catch (\Throwable $e) {
            // Silently fall back
        }

        return null;
    }

    /**
     * Check if an image URL or alt text indicates a site logo, favicon, or advertisement.
     */
    protected function isLogoOrAd(string $url, string $alt = ''): bool
    {
        $haystack = strtolower($url . ' ' . $alt);

        $badPatterns = [
            'logo', 'small-logo', 'black-logo', 'white-logo', 'header-logo',
            'favicon', 'icon', 'advertisement', 'বিজ্ঞাপন', 'sponsor',
            'banner-ad', 'avatar', 'loader', 'loading', 'placeholder', 'pixel',
        ];

        foreach ($badPatterns as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert relative URL to absolute based on the base page URL.
     */
    protected function resolveAbsoluteUrl(string $candidate, string $baseUrl): string
    {
        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            return $candidate;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (str_starts_with($candidate, '//')) {
            return $scheme . ':' . $candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return "{$scheme}://{$host}{$candidate}";
        }

        return "{$scheme}://{$host}/" . ltrim($candidate, '/');
    }

    /**
     * Parse Jina Reader raw markdown into structured components.
     */
    protected function parseJinaOutput(string $content, string $sourceUrl): array
    {
        $title = null;
        $featuredImage = null;
        $links = [];

        // 1. Extract Title
        if (preg_match('/^Title:\s*(.+)$/mi', $content, $matches)) {
            $title = trim($matches[1]);
        } elseif (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            $title = trim($matches[1]);
        }

        // 2. Extract Featured Image (Scan markdown images, skipping logos/ads)
        if (preg_match_all('/!\[([^\]]*)\]\(((?:https?:\/\/|\/)[^\s\)]+)\)/i', $content, $imgMatches, PREG_SET_ORDER)) {
            foreach ($imgMatches as $match) {
                $alt = $match[1] ?? '';
                $rawImg = $match[2] ?? '';

                if ($this->isLogoOrAd($rawImg, $alt)) {
                    continue;
                }

                $resolved = $this->resolveAbsoluteUrl($rawImg, $sourceUrl);
                $featuredImage = $resolved;
                break; // Pick the first genuine content image
            }
        }

        // 3. Extract Links from Markdown: [Text](URL)
        if (preg_match_all('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s\)]+)\)/i', $content, $linkMatches)) {
            $parsedSource = parse_url($sourceUrl);
            $sourceHost = strtolower($parsedSource['host'] ?? '');

            foreach ($linkMatches[2] as $rawLink) {
                $trimmed = trim($rawLink);

                // Convert relative to absolute
                $trimmed = $this->resolveAbsoluteUrl($trimmed, $sourceUrl);

                // Clean anchor and query params
                $cleanCandidate = preg_replace('/#.*$/', '', $trimmed);

                if (filter_var($cleanCandidate, FILTER_VALIDATE_URL)) {
                    $candHost = strtolower(parse_url($cleanCandidate, PHP_URL_HOST) ?? '');
                    $candPath = strtolower(parse_url($cleanCandidate, PHP_URL_PATH) ?? '');

                    // Skip static media files (images, audio, video, documents)
                    if (preg_match('/\.(jpe?g|png|webp|gif|svg|avif|ico|mp4|webm|pdf|zip|css|js)$/i', $candPath)) {
                        continue;
                    }

                    // Only include links from the same domain or subdomain
                    if ($candHost === $sourceHost || str_ends_with($candHost, '.' . $sourceHost) || str_ends_with($sourceHost, '.' . $candHost)) {
                        $links[] = $cleanCandidate;
                    }
                }
            }
        }

        $links = array_values(array_unique($links));

        // 4. Clean Markdown Content (remove Title: and URL Source: headers from top if present)
        $cleanMarkdown = preg_replace('/^(?:Title:.*|URL Source:.*|Markdown Content:)\s*\n/mi', '', $content);
        $cleanMarkdown = trim($cleanMarkdown);

        return [
            'title' => $title,
            'markdown' => $cleanMarkdown,
            'featured_image' => $featuredImage,
            'links' => $links,
        ];
    }
}
