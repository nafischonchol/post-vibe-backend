<?php

namespace App\Contracts;

interface AiServiceInterface
{
    /**
     * Generate text completion from a single prompt.
     *
     * @param string $prompt
     * @param array $options Model, temperature, max_tokens, etc.
     * @return string
     */
    public function generateText(string $prompt, array $options = []): string;

    /**
     * Generate completion from a chat message thread.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array $options
     * @return string
     */
    public function generateChat(array $messages, array $options = []): string;

    /**
     * Generate an SEO-friendly URL slug for a combo offer based on its title,
     * description, and bundled items.
     *
     * @param string $name
     * @param string|null $banglaName
     * @param string|null $shortDescription
     * @param array<int, string> $bundledProducts
     * @return string URL-safe slug string (e.g. "summer-hydration-glow-routine")
     */
    public function generateComboSlug(
        string $name,
        ?string $banglaName = null,
        ?string $shortDescription = null,
        array $bundledProducts = []
    ): string;

    /**
     * Generate a structured blog post with SEO tags, excerpt, and clean HTML.
     *
     * @param string $topic
     * @param array $options
     * @return array{
     *     title: string,
     *     slug: string,
     *     suggested_category_id: int|null,
     *     excerpt: string,
     *     content_html: string,
     *     meta_title: string,
     *     meta_description: string,
     *     meta_keywords: string,
     *     keywords: array<string>,
     *     suggested_tags: array<string>
     * }
     */
    public function generateBlogPost(string $topic, array $options = []): array;

    /**
     * Generate dual-language product description and routine guide.
     *
     * @param string $productName
     * @param array $attributes
     * @return array{description: string, bangla_description: string}
     */
    public function generateProductDescription(string $productName, array $attributes = []): array;

    /**
     * Test connection and credentials with the AI provider.
     *
     * @param string|null $apiKey Optional key override to test before saving
     * @param string|null $model Optional model override to test
     * @return array{success: bool, message: string, latency_ms?: int, model?: string}
     */
    public function testConnection(?string $apiKey = null, ?string $model = null): array;

    /**
     * Rewrite external article content into high-utility, user-friendly, and informative Bangla.
     * Selects appropriate category from available options.
     *
     * @param string $rawMarkdown
     * @param array $categories
     * @param array $options
     * @return array{
     *     title: string,
     *     slug: string,
     *     category_id: int|null,
     *     excerpt: string,
     *     content_html: string,
     *     meta_title: string,
     *     meta_description: string,
     *     meta_keywords: string
     * }
     */
    public function rewriteArticleToBangla(string $rawMarkdown, array $categories = [], array $options = []): array;

    /**
     * Check whether the AI service is properly configured and active.
     */
    public function isConfigured(): bool;
}
