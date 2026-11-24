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

    public function test_get_user_agent_returns_standard_browser_agent(): void
    {
        $ua = $this->service->getUserAgent();
        $this->assertNotEmpty($ua);
        $this->assertStringContainsString('Mozilla/5.0', $ua);
    }

    public function test_extract_feed_returns_empty_array_for_invalid_url(): void
    {
        $result = $this->service->extractFeed('https://invalid-non-existent-domain-12345.com/channel');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extract_metadata_returns_null_for_invalid_url(): void
    {
        $result = $this->service->extractMetadata('https://invalid-non-existent-domain-12345.com/video');
        $this->assertNull($result);
    }

    public function test_parse_duration_string_handles_various_formats(): void
    {
        $this->assertEquals(10, $this->service->parseDurationString('10'));
        $this->assertEquals(10, $this->service->parseDurationString('0:10'));
        $this->assertEquals(90, $this->service->parseDurationString('1:30'));
        $this->assertEquals(3665, $this->service->parseDurationString('01:01:05'));
        $this->assertNull($this->service->parseDurationString(''));
        $this->assertNull($this->service->parseDurationString(null));
    }

    public function test_get_cookies_path(): void
    {
        // When file doesn't exist, returns null
        config(['services.yt_dlp.cookies_path' => '/tmp/non_existent_cookies_file_12345.txt']);
        $this->assertNull($this->service->getCookiesPath());

        // When file exists and has content
        $tempCookieFile = tempnam(sys_get_temp_dir(), 'test_cookies');
        file_put_contents($tempCookieFile, "# Netscape HTTP Cookie File\n.instagram.com TRUE / FALSE 1999999999 sessionid test");
        config(['services.yt_dlp.cookies_path' => $tempCookieFile]);

        $this->assertEquals($tempCookieFile, $this->service->getCookiesPath());

        @unlink($tempCookieFile);
    }
}
