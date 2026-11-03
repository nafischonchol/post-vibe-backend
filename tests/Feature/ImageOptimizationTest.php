<?php

namespace Tests\Feature;

use App\Jobs\OptimizeImageJob;
use App\Models\Banner;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_optimize_image_job_converts_to_webp_and_updates_database()
    {
        Storage::fake('public');

        // Create a fake test JPEG file
        $im = imagecreatetruecolor(600, 400);
        $color = imagecolorallocate($im, 100, 150, 200);
        imagefilledrectangle($im, 0, 0, 600, 400, $color);
        ob_start();
        imagejpeg($im, null, 90);
        $jpegData = ob_get_clean();

        $originalPath = 'banners/promo_banner.jpg';
        Storage::disk('public')->put($originalPath, $jpegData);

        $banner = Banner::create([
            'type' => 'hero',
            'banner_image' => $originalPath,
            'is_active' => true,
        ]);

        $this->assertTrue(Storage::disk('public')->exists($originalPath));

        // Execute optimization job
        (new OptimizeImageJob($originalPath, 'public'))->handle();

        $expectedWebpPath = 'banners/promo_banner.webp';

        // Check WebP file exists and original was deleted
        $this->assertTrue(Storage::disk('public')->exists($expectedWebpPath));
        $this->assertFalse(Storage::disk('public')->exists($originalPath));

        // Check file size is < 200KB
        $size = Storage::disk('public')->size($expectedWebpPath);
        $this->assertLessThan(200 * 1024, $size);

        // Check database reference was updated
        $banner->refresh();
        $this->assertEquals($expectedWebpPath, $banner->banner_image);
    }
}
