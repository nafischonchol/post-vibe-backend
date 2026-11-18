<?php

namespace Tests\Unit;

use App\Services\Video\YtDlpService;
use Tests\TestCase;

class YtDlpServiceTest extends TestCase
{
    protected YtDlpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new YtDlpService();
    }

    public function test_binary_path_resolution(): void
    {
        $path = $this->service->getBinaryPath();

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertTrue(is_executable($path));
    }

    public function test_service_reports_availability(): void
    {
        $this->assertTrue($this->service->isAvailable());

        $version = $this->service->getVersion();
        $this->assertNotNull($version);
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{2}\.\d{2}/', $version);
    }

    public function test_normalize_extractor_categorizes_platforms_accurately(): void
    {
        $this->assertEquals('youtube', $this->service->normalizeExtractor('youtube', 'https://www.youtube.com/watch?v=123'));
        $this->assertEquals('youtube', $this->service->normalizeExtractor('YoutubeTab', 'https://youtu.be/123'));

        $this->assertEquals('tiktok', $this->service->normalizeExtractor('TikTok', 'https://www.tiktok.com/@user/video/123'));
        $this->assertEquals('instagram', $this->service->normalizeExtractor('Instagram', 'https://www.instagram.com/reel/123'));
        $this->assertEquals('facebook', $this->service->normalizeExtractor('facebook', 'https://www.facebook.com/reel/123'));
        $this->assertEquals('facebook', $this->service->normalizeExtractor('facebook', 'https://fb.watch/123'));

        $this->assertEquals('generic', $this->service->normalizeExtractor('generic', 'https://vimeo.com/123'));
    }

    public function test_extract_metadata_returns_null_for_invalid_url(): void
    {
        $result = $this->service->extractMetadata('https://invalid-non-existent-domain-12345.com/video');
        $this->assertNull($result);
    }
}
