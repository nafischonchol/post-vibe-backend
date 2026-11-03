<?php

namespace App\Services\Admin;

use App\Contracts\AiServiceInterface;
use App\Jobs\OptimizeImageJob;
use App\Models\Admin;
use App\Models\BlogCategory;
use App\Models\BlogCurationSetting;
use App\Models\BlogCuratedLog;
use App\Models\BlogPost;
use App\Models\BlogSource;
use App\Services\AI\JinaReaderService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogCurationService
{
    public function __construct(
        protected JinaReaderService $jinaReader,
        protected ?AiServiceInterface $aiService = null
    ) {
        $this->aiService = $aiService ?? app(AiServiceInterface::class);
    }

    /**
     * Run curation for all active sources or a specific source.
     *
     * @param int|null $specificSourceId
     * @param int $limitPerSource Max articles to process per source
     * @param bool $force Force processing even if daily limit or schedule was reached
     * @return array{sources_checked: int, processed: int, skipped: int, failed: int, details: array}
     */
    public function runCuration(?int $specificSourceId = null, int $limitPerSource = 3, bool $force = false): array
    {
        $isAutoEnabled = BlogCurationSetting::isAutoCurateEnabled();
        if (! $isAutoEnabled && ! $force && ! $specificSourceId) {
            Log::info("Automated blog curation is disabled in settings. Skipping.");
            return [
                'sources_checked' => 0,
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'limit_reached' => false,
                'message' => 'Automated blog curation is paused in settings.',
                'details' => [],
            ];
        }

        $dailyLimit = BlogCurationSetting::getDailyLimit();
        $remainingToday = BlogCurationSetting::getRemainingToday();

        if ($dailyLimit > 0 && $remainingToday <= 0 && ! $force) {
            Log::info("Daily AI blog post limit reached ({$dailyLimit}/day). Skipping curation.");
            return [
                'sources_checked' => 0,
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'limit_reached' => true,
                'daily_limit' => $dailyLimit,
                'today_published' => BlogCurationSetting::getTodayPublishedCount(),
                'message' => "Daily AI auto-post limit of {$dailyLimit} articles reached for today.",
                'details' => [],
            ];
        }

        $query = BlogSource::query()->where('is_active', true);
        if ($specificSourceId) {
            $query->where('id', $specificSourceId);
        }

        $sources = $query->get();
        $summary = [
            'sources_checked' => $sources->count(),
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'limit_reached' => false,
            'details' => [],
        ];

        foreach ($sources as $source) {
            $sourceResult = [
                'source_id' => $source->id,
                'source_name' => $source->name ?: $source->url,
                'articles_found' => 0,
                'articles_processed' => 0,
                'errors' => [],
            ];

            try {
                $candidateUrls = $this->discoverArticles($source, $limitPerSource * 2);
                $sourceResult['articles_found'] = count($candidateUrls);

                $processedCount = 0;
                foreach ($candidateUrls as $articleUrl) {
                    if ($processedCount >= $limitPerSource) {
                        break;
                    }

                    if (BlogCuratedLog::isUrlProcessed($articleUrl)) {
                        $summary['skipped']++;
                        continue;
                    }

                    try {
                        $post = $this->curateArticle($articleUrl, $source);
                        if ($post) {
                            $summary['processed']++;
                            $processedCount++;
                            $sourceResult['articles_processed']++;

                            // If daily limit reached during this run, stop immediately
                            if ($dailyLimit > 0 && BlogCurationSetting::getRemainingToday() <= 0 && ! $force) {
                                Log::info("Daily AI blog curation limit reached ({$dailyLimit}/day). Halting further articles.");
                                $summary['limit_reached'] = true;
                                $summary['message'] = "Daily limit of {$dailyLimit} articles reached.";
                                break 2;
                            }
                        } else {
                            $summary['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $summary['failed']++;
                        $sourceResult['errors'][] = [
                            'url' => $articleUrl,
                            'error' => $e->getMessage(),
                        ];
                        Log::error("Failed to curate article [{$articleUrl}]: " . $e->getMessage());
                    }
                }

                $source->update(['last_curated_at' => now()]);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $sourceResult['errors'][] = [
                    'source_url' => $source->url,
                    'error' => $e->getMessage(),
                ];
                Log::error("Failed discovering articles for source [{$source->url}]: " . $e->getMessage());
            }

            $summary['details'][] = $sourceResult;
        }

        return $summary;
    }

    /**
     * Discover article links from a blog source URL using Jina Reader.
     *
     * @param BlogSource $source
     * @param int $maxCandidates
     * @return array<string>
     */
    public function discoverArticles(BlogSource $source, int $maxCandidates = 10): array
    {
        $parsed = parse_url($source->url);
        $path = trim($parsed['path'] ?? '', '/');

        // Check if the source URL is already a single article URL (e.g. has a deep path with hyphenated slug)
        if (! empty($path) && ! str_contains($path, 'category') && ! str_contains($path, 'page') && ! str_contains($path, 'blog') && str_contains($path, '-')) {
            return [$source->url];
        }

        $jinaData = $this->jinaReader->fetch($source->url);
        $links = $jinaData['links'] ?? [];

        $ignoredKeywords = [
            '/tag/', '/tags/', '/category/', '/categories/', '/author/', '/page/',
            '/pages/', '/products/', '/product/', '/collections/', '/collection/',
            '/policies/', '/search', '/cart', '/checkout', '/account', '/login', '/signup',
            '/privacy', '/terms', '/contact', '/about', '/shop',
            '/storage/', '/media/', '/uploads/', '/assets/', '/static/', '/cdn/', '/images/', '/img/',
            '/wp-content', '/wp-admin', '/feed', '.xml', '.rss', '#',
        ];

        $candidates = [];
        $sourceHost = strtolower($parsed['host'] ?? '');

        foreach ($links as $link) {
            $parsedLink = parse_url($link);
            $linkPath = strtolower($parsedLink['path'] ?? '');

            // Skip if missing path or just home
            if (empty($linkPath) || $linkPath === '/' || strlen($linkPath) < 5) {
                continue;
            }

            // Skip static media files
            if (preg_match('/\.(jpe?g|png|webp|gif|svg|avif|ico|mp4|webm|pdf|zip|css|js)$/i', $linkPath)) {
                continue;
            }

            // Check ignored patterns
            $isIgnored = false;
            foreach ($ignoredKeywords as $kw) {
                if (str_contains($linkPath, $kw)) {
                    $isIgnored = true;
                    break;
                }
            }

            if ($isIgnored) {
                continue;
            }

            // Must look like an article (has a slug with hyphens or alphanumeric segments)
            if (preg_match('/[a-z0-9]+-[a-z0-9]+/i', $linkPath) || count(explode('/', trim($linkPath, '/'))) >= 2) {
                if (! BlogCuratedLog::isUrlProcessed($link)) {
                    $candidates[] = $link;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));
        return array_slice($candidates, 0, $maxCandidates);
    }

    /**
     * Curate, rewrite, download image, and publish a single article.
     *
     * @param string $articleUrl
     * @param BlogSource|null $source
     * @return BlogPost|null
     */
    public function curateArticle(string $articleUrl, ?BlogSource $source = null): ?BlogPost
    {
        $hash = BlogCuratedLog::makeHash($articleUrl);

        $log = BlogCuratedLog::firstOrNew(['original_url_hash' => $hash]);
        $log->original_url = $articleUrl;
        $log->blog_source_id = $source?->id;
        $log->status = 'pending';
        $log->save();

        try {
            // 1. Fetch clean content via r.jina.ai
            $jinaData = $this->jinaReader->fetch($articleUrl);
            $markdown = $jinaData['markdown'] ?? '';

            if (empty($markdown) || strlen($markdown) < 120) {
                throw new \RuntimeException('Extracted markdown content is too short or empty.');
            }

            $log->original_title = $jinaData['title'] ?? null;
            $log->save();

            // 2. Fetch available blog categories
            $categories = BlogCategory::query()->where('is_active', true)->select('id', 'name')->get()->toArray();
            if (empty($categories)) {
                $categories = BlogCategory::query()->select('id', 'name')->get()->toArray();
            }

            // 3. Rewrite into user-friendly informative Bangla via OpenRouter
            $aiData = null;
            $aiFailed = false;
            $aiErrorMessage = null;
            try {
                $aiData = $this->aiService->rewriteArticleToBangla($markdown, $categories, [
                    'temperature' => 0.65,
                    'max_tokens' => 7000,
                    'timeout' => 120,
                ]);
            } catch (\Throwable $e) {
                $aiFailed = true;
                $aiErrorMessage = $e->getMessage();
                Log::error("AI rewrite exception for [{$articleUrl}]: " . $e->getMessage());

                $cleanFallbackText = Str::limit(trim(strip_tags($markdown)), 12000);
                $fallbackTitle = ! empty($jinaData['title']) ? $jinaData['title'] : 'খসড়া ব্লগ নিবন্ধ (রিভিউ প্রয়োজন)';

                $aiData = [
                    'title' => $fallbackTitle,
                    'slug' => Str::slug($fallbackTitle) ?: 'draft-article-' . time(),
                    'category_id' => $source?->default_category_id ?? BlogCategory::first()?->id ?? 1,
                    'excerpt' => Str::limit($cleanFallbackText, 180),
                    'content_html' => '<p>' . nl2br(e($cleanFallbackText)) . '</p>',
                    'meta_title' => Str::limit($fallbackTitle, 60),
                    'meta_description' => Str::limit($cleanFallbackText, 150),
                    'meta_keywords' => '',
                    'is_failed' => true,
                    'is_fallback' => true,
                    'error_message' => $aiErrorMessage,
                ];
            }

            // 4. Download and optimize featured image
            $featuredImagePath = null;
            if (! empty($jinaData['featured_image'])) {
                $featuredImagePath = $this->downloadAndStoreImage($jinaData['featured_image']);
            }

            // 5. Determine category
            $categoryId = $aiData['category_id'] ?? null;
            if (! $categoryId || ! BlogCategory::where('id', $categoryId)->exists()) {
                $categoryId = $source?->default_category_id ?? BlogCategory::first()?->id ?? 1;
            }

            // 6. Ensure unique slug
            $baseSlug = Str::slug($aiData['slug'] ?: Str::slug($aiData['title'])) ?: 'beauty-tips-' . time();
            $slug = $baseSlug;
            $counter = 1;
            while (BlogPost::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            // 7. Determine author
            $adminId = Admin::first()?->id ?? 1;

            // 8. Determine post status:
            // If AI failed or fell back, or content is too short (<350 chars), or if source has auto_publish disabled, ALWAYS set status to 'draft'
            $contentLength = mb_strlen(strip_tags($aiData['content_html'] ?? ''));
            $isTooShort = $contentLength < 350;
            if ($isTooShort && empty($aiData['error_message'])) {
                $aiData['error_message'] = "Generated content is suspiciously short or incomplete ({$contentLength} chars). Saved as draft for manual review.";
            }

            $isFailed = $aiFailed || ! empty($aiData['is_failed']) || ! empty($aiData['is_fallback']) || $isTooShort;
            $postStatus = ($isFailed || ! ($source?->auto_publish ?? true)) ? 'draft' : 'published';

            // Create BlogPost (published if successful, draft if failed)
            $post = BlogPost::create([
                'admin_id' => $adminId,
                'blog_category_id' => $categoryId,
                'title' => $aiData['title'],
                'slug' => $slug,
                'excerpt' => $aiData['excerpt'],
                'content' => $aiData['content_html'],
                'featured_image' => $featuredImagePath,
                'thumbnail_image' => null,
                'status' => $postStatus,
                'published_at' => $postStatus === 'published' ? now() : null,
                'views_count' => 0,
                'is_featured' => false,
                'allow_comments' => true,
                'meta_title' => $aiData['meta_title'],
                'meta_description' => $aiData['meta_description'],
                'meta_keywords' => $aiData['meta_keywords'],
            ]);

            // 9. Update log
            $log->update([
                'blog_post_id' => $post->id,
                'status' => $postStatus === 'published' ? 'published' : ($isFailed ? 'failed' : 'pending'),
                'error_message' => $isFailed ? ($aiData['error_message'] ?? 'AI JSON parsing failed; saved as draft for manual review.') : null,
            ]);

            // 10. Revalidate Frontend Next.js Cache only if published
            if ($postStatus === 'published') {
                $this->revalidateFrontendCache();
            }

            return $post;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download image from remote URL and save to public storage.
     */
    protected function downloadAndStoreImage(string $imageUrl): ?string
    {
        try {
            $disk = config('filesystems.default');
            $response = Http::timeout(25)->retry(2, 500)->get($imageUrl);

            if (! $response->successful()) {
                Log::warning("Failed downloading image [{$imageUrl}]: HTTP " . $response->status());
                return null;
            }

            $content = $response->body();
            if (empty($content)) {
                return null;
            }

            // Determine extension
            $pathInfo = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '');
            $extension = strtolower($pathInfo['extension'] ?? 'jpg');
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'])) {
                $extension = 'jpg';
            }

            $storagePath = 'blogs/posts/' . Str::uuid() . '.' . $extension;

            $uploaded = Storage::disk($disk)->put($storagePath, $content, 'public');

            if ($uploaded) {
                // Dispatch background optimization job
                OptimizeImageJob::dispatch($storagePath, $disk);
                return $storagePath;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning("Image download exception for [{$imageUrl}]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Trigger Next.js revalidation for blogs and RSS feeds.
     */
    protected function revalidateFrontendCache(): void
    {
        try {
            $frontendUrl = rtrim(config('services.frontend_url', env('FRONTEND_URL', 'http://localhost:3090')), '/');
            $secret = config('services.revalidate_secret', env('REVALIDATE_SECRET', 'mohima-revalidate-secret-key-2026'));

            Http::timeout(5)->post("{$frontendUrl}/api/revalidate", [
                'secret' => $secret,
                'tags' => ['blogs', 'blog-categories', 'rss'],
            ]);
        } catch (\Throwable $e) {
            Log::info("Frontend revalidation trigger skipped or timed out: " . $e->getMessage());
        }
    }
}
