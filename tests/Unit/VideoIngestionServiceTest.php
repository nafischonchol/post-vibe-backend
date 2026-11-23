<?php

namespace Tests\Unit;

use App\Services\Video\VideoIngestionService;
use App\Services\Video\VideoScraperService;
use App\Services\Video\YtDlpService;
use Tests\TestCase;

class VideoIngestionServiceTest extends TestCase
{
    protected VideoIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VideoIngestionService(
            $this->createMock(VideoScraperService::class),
            $this->createMock(YtDlpService::class)
        );
    }

    public function test_is_direct_video_url_recognizes_facebook_urls(): void
    {
        // Facebook share links (user's specific format)
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/share/r/1dYDsu4dhp/'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/share/v/1dYDsu4dhp/'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/share/p/1dYDsu4dhp/'));

        // Facebook reel, watch, video links
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/reel/29485988027684805'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/reels/29485988027684805'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/watch/?v=29485988027684805'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://fb.watch/xyz123/'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.facebook.com/user/videos/12345678/'));

        // Profile/channel pages should not be direct video URLs
        $this->assertFalse($this->service->isDirectVideoUrl('https://www.facebook.com/OsamasView'));
        $this->assertFalse($this->service->isDirectVideoUrl('https://www.facebook.com/profile.php?id=123'));
    }

    public function test_is_direct_video_url_recognizes_other_platforms(): void
    {
        // YouTube
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertFalse($this->service->isDirectVideoUrl('https://www.youtube.com/@channelName'));

        // TikTok
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.tiktok.com/@user/video/71234567890'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://vm.tiktok.com/ZM123456/'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.tiktok.com/t/ZT123456/'));
        $this->assertFalse($this->service->isDirectVideoUrl('https://www.tiktok.com/@channelName'));

        // Instagram
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.instagram.com/reel/C8xyz123/'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.instagram.com/p/C8xyz123/'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://www.instagram.com/share/reel/C8xyz123/'));
        $this->assertFalse($this->service->isDirectVideoUrl('https://www.instagram.com/channelName/'));

        // Direct video files
        $this->assertTrue($this->service->isDirectVideoUrl('https://example.com/videos/clip.mp4'));
        $this->assertTrue($this->service->isDirectVideoUrl('https://example.com/videos/clip.webm'));

        // Invalid / null
        $this->assertFalse($this->service->isDirectVideoUrl(null));
        $this->assertFalse($this->service->isDirectVideoUrl(''));
        $this->assertFalse($this->service->isDirectVideoUrl('not-a-valid-url'));
    }
}
