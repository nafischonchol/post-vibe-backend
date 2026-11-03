<?php

namespace App\Services\AI;

use App\Contracts\AiServiceInterface;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenRouterService implements AiServiceInterface
{
    protected ?string $apiKey;
    protected string $defaultModel;
    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    public function __construct(?string $apiKey = null, ?string $defaultModel = null)
    {
        $this->apiKey = $apiKey ?: AiSetting::getActiveApiKey();
        $this->defaultModel = $defaultModel ?: AiSetting::getActiveModel();
    }

    /**
     * Get the active API key.
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey ?: AiSetting::getActiveApiKey();
    }

    /**
     * Get the active default model.
     */
    public function getDefaultModel(): string
    {
        return $this->defaultModel ?: AiSetting::getActiveModel();
    }

    /**
     * Check whether AI is configured and enabled.
     */
    public function isConfigured(): bool
    {
        $key = $this->getApiKey();

        return ! empty($key) && AiSetting::isAiEnabled();
    }

    /**
     * Generate text completion from a single prompt.
     */
    public function generateText(string $prompt, array $options = []): string
    {
        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        return $this->generateChat($messages, $options);
    }

    /**
     * Generate completion from a chat message thread.
     */
    public function generateChat(array $messages, array $options = []): string
    {
        $apiKey = $options['api_key'] ?? $this->getApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException('OpenRouter API key is not configured. Please add your key in Admin Settings > AI Setup.');
        }

        $model = $options['model'] ?? $this->getDefaultModel();
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 1500;
        $timeout = $options['timeout'] ?? 30;

        $siteUrl = config('services.openrouter.site_url', 'http://localhost:3000');
        $siteName = config('services.openrouter.site_name', 'Mohima Beauty');

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'HTTP-Referer' => $siteUrl,
                'X-Title' => $siteName,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post($this->baseUrl . '/chat/completions', $payload);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMsg = $errorBody['error']['message'] ?? 'OpenRouter API request failed with status ' . $response->status();
                Log::error('OpenRouter API Error: ' . $errorMsg, ['status' => $response->status(), 'response' => $response->body()]);
                throw new \RuntimeException($errorMsg);
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            return trim($content);
        } catch (\Exception $e) {
            Log::error('OpenRouter Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate an SEO-friendly URL slug for a combo offer based on its title,
     * description, and bundled items.
     */
    public function generateComboSlug(
        string $name,
        ?string $banglaName = null,
        ?string $shortDescription = null,
        array $bundledProducts = []
    ): string {
        // Fallback default slug in case AI is disabled or fails
        $fallbackSlug = Str::slug($name);

        if (! $this->isConfigured()) {
            return $fallbackSlug;
        }

        $systemPrompt = <<<PROMPT
You are an expert e-commerce SEO specialist for an authentic skincare and beauty store.
Your job is to generate a single clean, concise, keyword-rich English URL slug for a product bundle/combo offer.
Rules:
1. Output ONLY the slug (e.g. "summer-hydration-glow-routine" or "acne-clearing-k-beauty-pack").
2. Lowercase letters and numbers only, separated by single hyphens.
3. 3 to 6 words maximum. Do not exceed 50 characters.
4. Do NOT include quotation marks, markdown formatting, explanations, or trailing IDs.
5. Translate or adapt any Bengali context into natural English keywords.
PROMPT;

        $userContext = "Combo Name: {$name}\n";
        if ($banglaName) {
            $userContext .= "Bangla Name: {$banglaName}\n";
        }
        if ($shortDescription) {
            $userContext .= "Short Summary: {$shortDescription}\n";
        }
        if (! empty($bundledProducts)) {
            $userContext .= 'Bundled Products: ' . implode(', ', $bundledProducts) . "\n";
        }

        try {
            $response = $this->generateChat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContext],
            ], [
                'temperature' => 0.3,
                'max_tokens' => 60,
                'timeout' => 12,
            ]);

            $cleanSlug = Str::slug(trim(str_replace(['"', "'", '`'], '', $response)));

            return ! empty($cleanSlug) ? $cleanSlug : $fallbackSlug;
        } catch (\Exception $e) {
            Log::warning('AI Combo slug generation failed, falling back to basic slug: ' . $e->getMessage());

            return $fallbackSlug;
        }
    }

    /**
     * Generate a structured blog post with SEO tags, excerpt, and clean HTML.
     */
    public function generateBlogPost(string $topic, array $options = []): array
    {
        $language = $options['language'] ?? 'auto';
        $tone = $options['tone'] ?? 'informative';
        $categories = $options['categories'] ?? [];
        $existingTitle = $options['existing_title'] ?? null;
        $existingExcerpt = $options['existing_excerpt'] ?? null;
        $existingContent = $options['existing_content'] ?? null;
        $targetFields = $options['target_fields'] ?? null;

        // Categories prompt context
        $categoryContext = '';
        if (! empty($categories) && is_array($categories)) {
            $catList = array_map(function ($c) {
                return ['id' => $c['id'], 'name' => $c['name']];
            }, $categories);
            $categoryContext = "\nAvailable blog categories: " . json_encode($catList, JSON_UNESCAPED_UNICODE) . ". Pick the best matching category id for 'suggested_category_id', or null if none fit.";
        }

        // Tone guidance
        $toneInstructions = match ($tone) {
            'dermatologist' => 'Tone: Authoritative, clinical yet accessible, science-backed skincare advice.',
            'promotional' => 'Tone: Enthusiastic, compelling, highlighting product benefits and self-care routines.',
            'casual' => 'Tone: Warm, conversational, friendly beauty-enthusiast vibe.',
            default => 'Tone: Informative, helpful, balanced, and expert-guided.',
        };

        // Language guidance
        $languageInstructions = <<<LANG
Language Rules:
- If language is "bn" or if input/topic is in Bengali:
  * "title", "excerpt", and "content_html" MUST be in natural, refined Bengali (বাংলা).
  * "slug" MUST ALWAYS be in English ASCII kebab-case (e.g. "best-sunscreen-for-oily-skin"), NEVER Bengali script.
  * "meta_title" and "meta_description" MUST be in Bengali.
- If language is "en" or if input/topic is in English:
  * All fields in English. "slug" in clean ASCII kebab-case.
- "slug" must be URL-safe lowercase kebab-case (a-z, 0-9, hyphens only).
LANG;

        $systemPrompt = <<<PROMPT
You are a senior beauty, skincare, and cosmetics editor for Mohima, a premier authentic beauty and wellness brand.
Generate a high-utility, engaging, SEO-optimized blog article.

Strict Anti-AI Slop Guidelines:
- DO NOT start with generic fluff or cliches like "In today's fast-paced world...", "In recent years...", "Dive into...", "Welcome to...", or "Look no further...".
- Jump straight into practical, authoritative advice, common skin concerns, and actionable solutions.
- Format "content_html" with clean semantic HTML: <h2> for main sections, <h3> for sub-points, <p> for paragraphs, <ul>/<li> for lists, <strong> for emphasis. DO NOT include <html>, <body>, <head>, or markdown code block markers inside content_html.
- Content Completeness & Escaping Safety:
  * Write a comprehensive, complete article (at least 600-1200 words) with intro, structured sub-points, and conclusion. Do NOT cut off mid-article.
  * Inside "content_html" and "excerpt", NEVER use unescaped double quotes ("). If quotes are needed for words or names, use single quotes (') or Bengali quotes (‘...’), or escape them properly (\").
- SEO constraints:
  * "meta_title": Under 60 characters, brand-aligned.
  * "meta_description": Compelling snippet under 155 characters.
  * "meta_keywords": Comma-separated list of 4-8 relevant keywords.
  * "excerpt": 120-180 words summarizing the key takeaway.

{$toneInstructions}
{$languageInstructions}
{$categoryContext}

You must respond ONLY with a valid JSON object matching this structure:
{
  "title": "Blog article title",
  "slug": "url-friendly-slug-in-english",
  "suggested_category_id": 1,
  "excerpt": "Concise 120-180 words summary",
  "content_html": "<h2>Section</h2><p>Article content...</p>",
  "meta_title": "SEO title under 60 chars",
  "meta_description": "Meta description under 155 chars",
  "meta_keywords": "keyword1, keyword2, keyword3"
}
PROMPT;

        $userPrompt = "Generate or complete the blog post based on the following input:\n";
        $userPrompt .= "Primary Topic / Working Title: {$topic}\n";

        if (! empty($existingTitle) && $existingTitle !== $topic) {
            $userPrompt .= "User Provided Title: {$existingTitle}\n";
        }
        if (! empty($existingExcerpt)) {
            $userPrompt .= "User Provided Excerpt/Notes: {$existingExcerpt}\n";
        }
        if (! empty($existingContent)) {
            $cleanExisting = Str::limit(strip_tags($existingContent), 1000);
            $userPrompt .= "User Draft / Content Outline: {$cleanExisting}\n";
        }
        if (! empty($options['keywords'])) {
            $userPrompt .= 'Target Keywords: ' . (is_array($options['keywords']) ? implode(', ', $options['keywords']) : $options['keywords']) . "\n";
        }
        if (! empty($targetFields) && is_array($targetFields)) {
            $userPrompt .= 'Special priority fields to focus on: ' . implode(', ', $targetFields) . "\n";
        }

        try {
            $rawJson = $this->generateChat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ], array_merge([
                'temperature' => 0.7,
                'max_tokens' => 7000,
                'timeout' => 120,
                'response_format' => ['type' => 'json_object'],
            ], $options));

            $parsed = $this->extractAndDecodeJson($rawJson);

            if (! is_array($parsed) || empty($parsed['title'])) {
                $fallbackSlug = Str::slug($topic);

                $cleanSnippet = preg_replace('/```(?:json)?[\s\S]*?```/i', '', $rawJson);
                $cleanSnippet = preg_replace('/[{}"\[\]]|(?:\b(?:title|slug|content_html|excerpt)\b\s*:)/i', '', $cleanSnippet);
                $cleanSnippet = trim(strip_tags($cleanSnippet));
                if (empty($cleanSnippet)) {
                    $cleanSnippet = $topic;
                }

                return [
                    'title' => $topic,
                    'slug' => ! empty($fallbackSlug) ? $fallbackSlug : 'blog-post-' . time(),
                    'suggested_category_id' => null,
                    'excerpt' => Str::limit($cleanSnippet, 250),
                    'content_html' => '<p>' . nl2br(e(Str::limit($cleanSnippet, 12000))) . '</p>',
                    'meta_title' => Str::limit($topic, 60),
                    'meta_description' => Str::limit($cleanSnippet, 150),
                    'meta_keywords' => '',
                    'keywords' => [],
                    'suggested_tags' => [],
                    'is_failed' => true,
                    'is_fallback' => true,
                ];
            }

            $rawSlug = $parsed['slug'] ?? '';
            $cleanSlug = Str::slug($rawSlug);
            if (empty($cleanSlug)) {
                $cleanSlug = Str::slug($parsed['title'] ?? $topic);
            }
            if (empty($cleanSlug)) {
                $cleanSlug = 'article-' . time();
            }

            // Category validation
            $suggestedCatId = isset($parsed['suggested_category_id']) && is_numeric($parsed['suggested_category_id'])
                ? (int) $parsed['suggested_category_id']
                : null;

            // Keywords normalization
            $metaKeywords = '';
            if (isset($parsed['meta_keywords'])) {
                $metaKeywords = is_array($parsed['meta_keywords'])
                    ? implode(', ', $parsed['meta_keywords'])
                    : (string) $parsed['meta_keywords'];
            } elseif (isset($parsed['keywords']) && is_array($parsed['keywords'])) {
                $metaKeywords = implode(', ', $parsed['keywords']);
            }

            return [
                'title' => $parsed['title'] ?? $topic,
                'slug' => $cleanSlug,
                'suggested_category_id' => $suggestedCatId,
                'excerpt' => $parsed['excerpt'] ?? ($parsed['short_description'] ?? ''),
                'content_html' => $parsed['content_html'] ?? '',
                'meta_title' => $parsed['meta_title'] ?? Str::limit($parsed['title'] ?? $topic, 60),
                'meta_description' => $parsed['meta_description'] ?? Str::limit($parsed['excerpt'] ?? '', 155),
                'meta_keywords' => $metaKeywords,
                'keywords' => is_array($parsed['keywords'] ?? null) ? $parsed['keywords'] : array_map('trim', explode(',', $metaKeywords)),
                'suggested_tags' => is_array($parsed['suggested_tags'] ?? null) ? $parsed['suggested_tags'] : [],
            ];
        } catch (\Exception $e) {
            Log::error('AI Blog generation exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate dual-language product description and routine guide.
     */
    public function generateProductDescription(string $productName, array $attributes = []): array
    {
        $systemPrompt = <<<PROMPT
You are a skincare specialist. Generate an informative product guide in both English and Bengali (বাংলা).
Respond with valid JSON:
{
  "description": "HTML formatted English routine guide and benefits",
  "bangla_description": "HTML formatted Bengali (বাংলা) routine guide and benefits"
}
PROMPT;

        $rawJson = $this->generateChat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Product: {$productName}\nAttributes: " . json_encode($attributes)],
        ], ['temperature' => 0.5, 'max_tokens' => 1500]);

        $rawJson = preg_replace('/^```(?:json)?\s*/i', '', trim($rawJson));
        $rawJson = preg_replace('/\s*```$/', '', $rawJson);

        $parsed = json_decode($rawJson, true);

        return [
            'description' => $parsed['description'] ?? '',
            'bangla_description' => $parsed['bangla_description'] ?? '',
        ];
    }

    /**
     * Rewrite external article content into high-utility, user-friendly, and informative Bangla.
     */
    public function rewriteArticleToBangla(string $rawMarkdown, array $categories = [], array $options = []): array
    {
        // Limit raw markdown to ~8,000 chars to prevent token overrun while keeping core content
        $truncatedMarkdown = Str::limit(trim($rawMarkdown), 8000, '... [Content truncated for rewriting]');

        $catList = array_map(function ($c) {
            return ['id' => $c['id'], 'name' => $c['name']];
        }, $categories);
        $categoryContext = ! empty($catList)
            ? "Available Categories:\n" . json_encode($catList, JSON_UNESCAPED_UNICODE) . "\nSelect the best matching integer id for 'category_id', or null if none fit."
            : "No specific categories provided. Set 'category_id' to null.";

        $systemPrompt = <<<PROMPT
You are a senior beauty, skincare, and lifestyle content editor for "Mohima" (মোহিমা), a premier authentic beauty and personal care brand in Bangladesh.
Your task is to take an external article (provided in clean markdown) and rewrite it completely into an original, user-friendly, highly informative blog post in natural Bengali (বাংলা).

STRICT GUIDELINES:
1. Anti-AI Slop Standards:
   - DO NOT begin with generic filler or clichés such as "আজকের ব্যস্ত জীবনে...", "আমরা সবাই জানি...", "আপনি কি খুঁজছেন...", "In today's fast-paced world...".
   - Jump straight into the topic with actionable tips, benefits, dermatologist insights, or step-by-step guidance.
2. Language:
   - "title", "excerpt", "content_html", "meta_title", "meta_description" MUST be in natural, grammatically correct Bengali (বাংলা).
   - "slug" MUST ALWAYS be in English ASCII kebab-case (e.g., "hyaluronic-acid-benefits-for-dry-skin"). NEVER Bengali script in the slug.
3. Tone:
   - User-friendly, informative, engaging, warm, and helpful (সহজবোধ্য, প্রাসঙ্গিক ও তথ্যবহুল).
4. Content Formatting & Escaping Safety:
   - "content_html" must be structured with semantic HTML tags: <h2> for main sections, <h3> for sub-points, <p> for paragraphs, <ul>/<li> for lists, <strong> for emphasis.
   - DO NOT include `<html>`, `<head>`, `<body>`, or markdown ```html code fences in "content_html".
   - Write a complete, comprehensive article with introduction, detailed body points, and a concluding section. Do NOT cut off mid-article.
   - Inside "content_html" and "excerpt", NEVER use unescaped double quotes ("). If quotes are needed for words or names, use single quotes (') or Bengali quotes (‘...’), or escape them properly (\").
5. SEO:
   - "meta_title": Under 60 characters in Bengali.
   - "meta_description": Engaging meta description under 155 characters in Bengali.
   - "meta_keywords": 4-7 comma-separated relevant keywords (e.g., "স্কিনকেয়ার টিপস, গ্লোয়িং স্কিন, সানস্ক্রিন ব্যবহার").
6. Categorization:
   {$categoryContext}

Respond ONLY with a valid JSON object matching this structure:
{
  "title": "বাংলা টাইটেল",
  "slug": "url-friendly-slug-in-english",
  "category_id": 1,
  "excerpt": "বাংলা সামারি ১০০-১৫০ শব্দে",
  "content_html": "<h2>সেকশন টাইটেল</h2><p>অনুচ্ছেদ...</p>",
  "meta_title": "এসইও টাইটেল ৬০ অক্ষরের মধ্যে",
  "meta_description": "মেটা ডেসক্রিপশন ১৫৫ অক্ষরের মধ্যে",
  "meta_keywords": "কিওয়ার্ড ১, কিওয়ার্ড ২, কিওয়ার্ড ৩"
}
PROMPT;

        $userPrompt = "Please rewrite the following source article into user-friendly informative Bengali:\n\n" . $truncatedMarkdown;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $rawResponse = $this->generateChat($messages, array_merge([
            'temperature' => 0.65,
            'max_tokens' => 7000,
            'timeout' => 120,
            'response_format' => ['type' => 'json_object'],
        ], $options));

        $decoded = $this->extractAndDecodeJson($rawResponse);

        if (! is_array($decoded) || empty($decoded['title'])) {
            Log::warning('OpenRouter rewriteArticleToBangla JSON decode failed: ' . $rawResponse);

            // Strip any raw markdown fences or json keys so they never leak into the blog
            $cleanText = preg_replace('/```(?:json)?[\s\S]*?```/i', '', $rawResponse);
            $cleanText = preg_replace('/[{}"\[\]]|(?:\b(?:title|slug|content_html|excerpt|meta_title|meta_description)\b\s*:)/i', '', $cleanText);
            $cleanText = trim(strip_tags($cleanText));
            if (empty($cleanText) || strlen($cleanText) < 20) {
                $cleanText = trim(strip_tags($rawMarkdown));
            }

            return [
                'title' => 'খসড়া ব্লগ নিবন্ধ (রিভিউ প্রয়োজন)',
                'slug' => 'draft-article-' . time(),
                'category_id' => ! empty($categories) ? $categories[0]['id'] : null,
                'excerpt' => Str::limit($cleanText, 250),
                'content_html' => '<p>' . nl2br(e(Str::limit($cleanText, 12000))) . '</p>',
                'meta_title' => 'খসড়া ব্লগ নিবন্ধ - মোহিমা',
                'meta_description' => Str::limit($cleanText, 150),
                'meta_keywords' => '',
                'is_failed' => true,
                'is_fallback' => true,
                'error_message' => 'AI JSON parsing failed. Saved as draft for manual review.',
            ];
        }

        return [
            'title' => trim($decoded['title']),
            'slug' => Str::slug($decoded['slug'] ?? Str::slug($decoded['title'])) ?: 'beauty-guide-' . time(),
            'category_id' => ! empty($decoded['category_id']) ? (int) $decoded['category_id'] : (! empty($categories) ? $categories[0]['id'] : null),
            'excerpt' => trim($decoded['excerpt'] ?? ''),
            'content_html' => trim($decoded['content_html'] ?? ''),
            'meta_title' => trim($decoded['meta_title'] ?? $decoded['title']),
            'meta_description' => trim($decoded['meta_description'] ?? ''),
            'meta_keywords' => trim($decoded['meta_keywords'] ?? ''),
            'is_failed' => false,
            'is_fallback' => false,
        ];
    }

    /**
     * Robustly extract and decode JSON from AI responses.
     * Handles markdown code fences, unescaped quotes, unescaped whitespace, surrounding text, and truncated JSON.
     */
    protected function extractAndDecodeJson(string $rawText): ?array
    {
        $text = trim($rawText);
        if (empty($text)) {
            return null;
        }

        // 1. Strip code fence blocks (including cases where opening fence exists but closing fence was cut off)
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        // 2. Direct JSON decode
        $decoded = json_decode($cleaned, true);
        if (is_array($decoded) && (! empty($decoded['title']) || ! empty($decoded['content_html']) || ! empty($decoded['content']))) {
            return $decoded;
        }

        // 3. Extract from first '{' to last '}'
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && (! empty($decoded['title']) || ! empty($decoded['content_html']) || ! empty($decoded['content']))) {
                return $decoded;
            }

            // Remove non-printable control characters that break JSON decoding (except standard spacing)
            $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $candidate);
            $decoded = json_decode($sanitized, true);
            if (is_array($decoded) && (! empty($decoded['title']) || ! empty($decoded['content_html']) || ! empty($decoded['content']))) {
                return $decoded;
            }
        }

        // 4. Smart key-aware field extraction for truncated or unescaped-quote JSON.
        // Prevents premature cutoff when AI outputs unescaped double quotes inside content_html.
        $knownKeys = [
            'title',
            'slug',
            'category_id',
            'suggested_category_id',
            'excerpt',
            'content_html',
            'content',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'keywords',
            'suggested_tags',
        ];
        $knownKeysPattern = implode('|', $knownKeys);

        $extracted = [];

        foreach ($knownKeys as $key) {
            // Check numeric fields
            if (in_array($key, ['category_id', 'suggested_category_id'])) {
                if (preg_match('/"' . $key . '"\s*:\s*(\d+)/u', $text, $m)) {
                    $extracted[$key] = (int) $m[1];
                }
                continue;
            }

            // Check array fields (e.g. meta_keywords as array)
            if (in_array($key, ['meta_keywords', 'keywords', 'suggested_tags'])) {
                if (preg_match('/"' . $key . '"\s*:\s*\[([^\]]*)\]/u', $text, $m)) {
                    $rawItems = array_map(function ($it) {
                        return trim(stripcslashes($it), " \t\n\r\0\x0B\"'");
                    }, explode(',', $m[1]));
                    $cleanItems = array_filter($rawItems, fn ($it) => $it !== '');
                    $extracted[$key] = implode(', ', $cleanItems);
                    continue;
                }
            }

            // Extract string field using lookahead to next known key or closing brace
            $patternWithLookahead = '/"' . $key . '"\s*:\s*"([\s\S]*?)"(?=\s*,\s*"(?:' . $knownKeysPattern . ')"\s*:|\s*\}\s*$)/u';
            if (preg_match($patternWithLookahead, $text, $m)) {
                $extracted[$key] = stripcslashes(trim($m[1]));
                continue;
            }

            // Special handling for content_html / content:
            // Do NOT stop at an internal quote! If there is no following key lookahead, capture all the way to the tail of the stream
            if (in_array($key, ['content_html', 'content'])) {
                if (preg_match('/"' . $key . '"\s*:\s*"([\s\S]+)$/u', $text, $m)) {
                    $tail = preg_replace('/"?(?:\s*\})?\s*$/', '', trim($m[1]));
                    $extracted[$key] = stripcslashes($tail);
                    continue;
                }
            }

            // Fallback for short fields when lookahead doesn't match: standard quote-terminated match
            if (preg_match('/"' . $key . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/u', $text, $m)) {
                $extracted[$key] = stripcslashes(trim($m[1]));
            }
        }

        if (! empty($extracted['content']) && empty($extracted['content_html'])) {
            $extracted['content_html'] = $extracted['content'];
        }

        // Ensure any unclosed HTML tags in content_html are cleanly closed
        if (! empty($extracted['content_html'])) {
            $extracted['content_html'] = $this->closeUnclosedHtmlTags($extracted['content_html']);
        }

        if (! empty($extracted['title']) && ! empty($extracted['content_html'])) {
            return $extracted;
        }

        return null;
    }

    /**
     * Ensure any unclosed HTML tags in truncated or stream-cut content are properly closed.
     */
    protected function closeUnclosedHtmlTags(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        preg_match_all('#<(/)?([a-z0-9]+)(?:\s+[^>]*?)?(?:/)?\s*>#i', $html, $matches, PREG_SET_ORDER);

        $openedTags = [];
        $voidTags = ['img', 'br', 'hr', 'input', 'meta', 'link'];

        foreach ($matches as $match) {
            $isClosing = ! empty($match[1]);
            $tagName = strtolower($match[2]);

            if (in_array($tagName, $voidTags)) {
                continue;
            }

            if ($isClosing) {
                $lastIndex = array_key_last($openedTags);
                if ($lastIndex !== null && $openedTags[$lastIndex] === $tagName) {
                    array_pop($openedTags);
                } else {
                    $key = array_search($tagName, array_reverse($openedTags, true));
                    if ($key !== false) {
                        unset($openedTags[$key]);
                        $openedTags = array_values($openedTags);
                    }
                }
            } else {
                $openedTags[] = $tagName;
            }
        }

        while (! empty($openedTags)) {
            $tag = array_pop($openedTags);
            $html .= "</{$tag}>";
        }

        return $html;
    }

    /**
     * Test connection and credentials with OpenRouter.
     */
    public function testConnection(?string $apiKey = null, ?string $model = null): array
    {
        $keyToTest = $apiKey ?: $this->getApiKey();
        if (empty($keyToTest)) {
            return [
                'success' => false,
                'message' => 'API Key is empty. Please enter an OpenRouter API key.',
            ];
        }

        $modelToTest = $model ?: $this->getDefaultModel();
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $keyToTest,
                'HTTP-Referer' => config('services.openrouter.site_url', 'http://localhost:3000'),
                'X-Title' => config('services.openrouter.site_name', 'Mohima Beauty'),
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($this->baseUrl . '/chat/completions', [
                'model' => $modelToTest,
                'messages' => [
                    ['role' => 'user', 'content' => 'Say "OK" and nothing else.'],
                ],
                'max_tokens' => 5,
                'temperature' => 0.1,
            ]);

            $elapsedMs = round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $data = $response->json();
                $reply = trim($data['choices'][0]['message']['content'] ?? 'OK');

                return [
                    'success' => true,
                    'message' => "Connected to OpenRouter successfully using model [{$modelToTest}].",
                    'latency_ms' => $elapsedMs,
                    'model' => $modelToTest,
                    'reply' => $reply,
                ];
            }

            $errorData = $response->json();
            $errorMsg = $errorData['error']['message'] ?? 'Authentication failed (HTTP ' . $response->status() . ').';

            return [
                'success' => false,
                'message' => $errorMsg,
                'latency_ms' => $elapsedMs,
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            $elapsedMs = round((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'latency_ms' => $elapsedMs,
            ];
        }
    }

    /**
     * Generate structured SEO bottom buying guide content and FAQs
     * for a category or brand in English and/or Bengali.
     */
    public function generateSeoContent(string $type, string $name, array $options = []): array
    {
        $language = $options['language'] ?? 'both'; // 'both', 'en', 'bn'
        $customPrompt = $options['prompt'] ?? '';
        $keywords = $options['keywords'] ?? '';

        $typeLabel = strtolower($type) === 'brand' ? 'Brand' : 'Product Category';

        $languageInstructions = match ($language) {
            'en' => "LANGUAGE REQUIREMENT: ENGLISH ONLY.\n- Populate 'seo_bottom_content' and 'faqs' in natural, professional English.\n- Set 'seo_bottom_content_bn' to empty string \"\" and 'faqs_bn' to empty array [].",
            'bn' => "LANGUAGE REQUIREMENT: BENGALI (বাংলা) ONLY.\n- Populate 'seo_bottom_content_bn' and 'faqs_bn' in fluent, natural Bengali.\n- Set 'seo_bottom_content' to empty string \"\" and 'faqs' to empty array [].",
            default => "LANGUAGE REQUIREMENT: BOTH English and Bengali (বাংলা).\n- Populate all four fields: English ('seo_bottom_content', 'faqs') and Bengali ('seo_bottom_content_bn', 'faqs_bn').",
        };

        $systemPrompt = <<<PROMPT
You are a premier e-commerce SEO copywriter and clinical skincare specialist for "Mohimaa" — a trusted authentic Korean Beauty & skincare platform in Bangladesh.
Your job is to generate a concise, high-ranking SEO Buying Guide and high-intent Frequently Asked Questions (FAQs) for a {$typeLabel} named: "{$name}".

{$languageInstructions}

GUIDELINES:
1. SEO Bottom Content (Buying Guide):
   - Provide a focused, educational buying guide (200–350 words) formatted in clean semantic HTML.
   - Use <h2>, <h3>, <p>, <ul>, <li>, and <strong> tags only.
   - Highlight skin types (Oily, Dry, Sensitive, Combination), active ingredients (e.g. Centella, Niacinamide, Hyaluronic Acid, Snail Mucin), routine tips, and Mohimaa's 100% genuine product guarantee in Bangladesh.
2. FAQs:
   - Provide 3 to 5 realistic questions and clear 2-sentence answers about this {$typeLabel}.
3. Formatting Rules:
   - Output strictly valid JSON matching the schema below.
   - Escape double quotes inside HTML and text strings with backslash (\").
   - Do NOT include unescaped raw newlines inside JSON string values.

JSON SCHEMA:
{
  "seo_bottom_content": "<h2>...</h2><p>...</p>",
  "seo_bottom_content_bn": "<h2>...</h2><p>...</p>",
  "faqs": [
    {"question": "...", "answer": "..."}
  ],
  "faqs_bn": [
    {"question": "...", "answer": "..."}
  ]
}
PROMPT;

        $userPrompt = "Target: {$typeLabel} \"{$name}\"\n";
        if (! empty($keywords)) {
            $userPrompt .= "Target Keywords: {$keywords}\n";
        }
        if (! empty($customPrompt)) {
            $userPrompt .= "Special Instructions: {$customPrompt}\n";
        }

        $rawResponse = $this->generateChat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ], [
            'temperature' => 0.4,
            'max_tokens' => 4000,
            'timeout' => 60,
            'response_format' => ['type' => 'json_object'],
        ]);

        $parsed = $this->extractSeoJsonFromText($rawResponse);

        if (! $parsed) {
            Log::warning('OpenRouter SEO JSON decoding failed. Raw: ' . substr($rawResponse, 0, 500));
            throw new \RuntimeException('Failed to parse AI response into structured JSON. Please try again.');
        }

        return $parsed;
    }

    /**
     * Extract structured SEO bottom content and FAQs from raw LLM output,
     * with multi-stage sanitization, unescaped quote recovery, and regex fallbacks.
     */
    public function extractSeoJsonFromText(string $text): ?array
    {
        $cleaned = trim($text);

        // 1. Strip markdown code fence blocks
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        // 2. Direct JSON decode
        $decoded = json_decode($cleaned, true);
        if (is_array($decoded) && (isset($decoded['seo_bottom_content']) || isset($decoded['seo_bottom_content_bn']) || isset($decoded['faqs']) || isset($decoded['faqs_bn']))) {
            return $this->formatSeoResult($decoded);
        }

        // 3. Extract JSON object substring between first '{' and last '}'
        $firstBrace = strpos($cleaned, '{');
        $lastBrace = strrpos($cleaned, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidate = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded) && (isset($decoded['seo_bottom_content']) || isset($decoded['seo_bottom_content_bn']) || isset($decoded['faqs']) || isset($decoded['faqs_bn']))) {
                return $this->formatSeoResult($decoded);
            }

            // Remove non-printable control characters that break JSON decoding (except standard spacing)
            $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $candidate);
            $decoded = json_decode($sanitized, true);
            if (is_array($decoded) && (isset($decoded['seo_bottom_content']) || isset($decoded['seo_bottom_content_bn']) || isset($decoded['faqs']) || isset($decoded['faqs_bn']))) {
                return $this->formatSeoResult($decoded);
            }

            // Fix unescaped newlines/tabs inside string literals
            $fixedNewlines = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/su', function ($m) {
                return '"' . str_replace(["\r\n", "\r", "\n", "\t"], ['\n', '\n', '\n', '\t'], $m[1]) . '"';
            }, $sanitized);
            $decoded = json_decode($fixedNewlines, true);
            if (is_array($decoded) && (isset($decoded['seo_bottom_content']) || isset($decoded['seo_bottom_content_bn']) || isset($decoded['faqs']) || isset($decoded['faqs_bn']))) {
                return $this->formatSeoResult($decoded);
            }
        }

        // 4. Fallback: Key-aware regex extraction
        $extracted = [
            'seo_bottom_content' => '',
            'seo_bottom_content_bn' => '',
            'faqs' => [],
            'faqs_bn' => [],
        ];

        // Extract seo_bottom_content
        if (preg_match('/"seo_bottom_content"\s*:\s*"([\s\S]*?)"(?=\s*,\s*"(?:seo_bottom_content_bn|faqs|faqs_bn)"\s*:|\s*\}\s*$)/u', $cleaned, $m)) {
            $extracted['seo_bottom_content'] = stripcslashes(trim($m[1]));
        }

        // Extract seo_bottom_content_bn
        if (preg_match('/"seo_bottom_content_bn"\s*:\s*"([\s\S]*?)"(?=\s*,\s*"(?:seo_bottom_content|faqs|faqs_bn)"\s*:|\s*\}\s*$)/u', $cleaned, $m)) {
            $extracted['seo_bottom_content_bn'] = stripcslashes(trim($m[1]));
        }

        // Extract faqs array
        if (preg_match('/"faqs"\s*:\s*\[([\s\S]*?)\](?=\s*,\s*"(?:seo_bottom_content|seo_bottom_content_bn|faqs_bn)"\s*:|\s*\}\s*$)/u', $cleaned, $m)) {
            $faqBlocks = '[' . trim($m[1]) . ']';
            $parsedFaqs = json_decode($faqBlocks, true);
            if (is_array($parsedFaqs)) {
                $extracted['faqs'] = $parsedFaqs;
            } else {
                preg_match_all('/\{\s*"question"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*,\s*"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*\}/u', $faqBlocks, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $extracted['faqs'][] = [
                        'question' => stripcslashes(trim($pair[1])),
                        'answer' => stripcslashes(trim($pair[2])),
                    ];
                }
            }
        }

        // Extract faqs_bn array
        if (preg_match('/"faqs_bn"\s*:\s*\[([\s\S]*?)\](?=\s*,\s*"(?:seo_bottom_content|seo_bottom_content_bn|faqs)"\s*:|\s*\}\s*$)/u', $cleaned, $m)) {
            $faqBnBlocks = '[' . trim($m[1]) . ']';
            $parsedFaqsBn = json_decode($faqBnBlocks, true);
            if (is_array($parsedFaqsBn)) {
                $extracted['faqs_bn'] = $parsedFaqsBn;
            } else {
                preg_match_all('/\{\s*"question"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*,\s*"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*\}/u', $faqBnBlocks, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $extracted['faqs_bn'][] = [
                        'question' => stripcslashes(trim($pair[1])),
                        'answer' => stripcslashes(trim($pair[2])),
                    ];
                }
            }
        }

        if (! empty($extracted['seo_bottom_content']) || ! empty($extracted['seo_bottom_content_bn']) || ! empty($extracted['faqs']) || ! empty($extracted['faqs_bn'])) {
            return $this->formatSeoResult($extracted);
        }

        return null;
    }

    protected function formatSeoResult(array $data): array
    {
        return [
            'seo_bottom_content' => ! empty($data['seo_bottom_content']) ? $this->closeUnclosedHtmlTags(trim($data['seo_bottom_content'])) : '',
            'seo_bottom_content_bn' => ! empty($data['seo_bottom_content_bn']) ? $this->closeUnclosedHtmlTags(trim($data['seo_bottom_content_bn'])) : '',
            'faqs' => is_array($data['faqs'] ?? null) ? array_values($data['faqs']) : [],
            'faqs_bn' => is_array($data['faqs_bn'] ?? null) ? array_values($data['faqs_bn']) : [],
        ];
    }
}

