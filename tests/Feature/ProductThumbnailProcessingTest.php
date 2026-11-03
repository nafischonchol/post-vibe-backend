<?php

namespace Tests\Feature;

use App\Actions\Product\CreateProductAction;
use App\Actions\Product\UpdateProductAction;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\Customer\ProductDetailsResource;
use App\Http\Resources\Customer\ProductResource;
use App\Jobs\ProcessProductThumbnailJob;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductThumbnailProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
    }

    private function createFakeImage(int $width, int $height, string $format = 'jpg'): string
    {
        $im = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($im, 120, 180, 240);
        imagefilledrectangle($im, 0, 0, $width, $height, $color);

        ob_start();
        if ($format === 'jpg' || $format === 'jpeg') {
            imagejpeg($im, null, 90);
        } else {
            imagewebp($im, null, 90);
        }
        $data = ob_get_clean();
        imagedestroy($im);

        return $data;
    }

    public function test_process_product_thumbnail_job_generates_thumb_full_and_blur_image()
    {
        $category = Category::create(['name' => 'Skin', 'slug' => 'skin', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'is_active' => true]);

        $originalPath = 'products/thumbnails/serum_original.jpg';
        Storage::disk('public')->put($originalPath, $this->createFakeImage(1600, 1200, 'jpg'));

        $product = Product::create([
            'name' => 'Snail Mucin Serum',
            'slug' => 'snail-mucin-serum',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'image' => $originalPath,
        ]);

        $product->images()->create([
            'image_path' => $originalPath,
            'is_main' => true,
        ]);

        // Execute job
        (new ProcessProductThumbnailJob($product->id, $originalPath, 'public'))->handle();

        $product->refresh();

        $expectedThumbPath = 'products/thumbnails/serum_original_thumb.webp';
        $expectedFullPath = 'products/thumbnails/serum_original.webp';

        // 1. Check small thumbnail
        $this->assertEquals($expectedThumbPath, $product->image);
        $this->assertTrue(Storage::disk('public')->exists($expectedThumbPath));
        $thumbSize = Storage::disk('public')->size($expectedThumbPath);
        $this->assertLessThan(30 * 1024, $thumbSize);

        // Verify dimensions of thumb <= 360
        $thumbData = Storage::disk('public')->get($expectedThumbPath);
        $thumbImg = imagecreatefromstring($thumbData);
        $this->assertLessThanOrEqual(360, imagesx($thumbImg));
        $this->assertLessThanOrEqual(360, imagesy($thumbImg));
        imagedestroy($thumbImg);

        // 2. Check full-size image in product_images
        $primaryImg = $product->images()->where('is_main', true)->first();
        $this->assertNotNull($primaryImg);
        $this->assertEquals($expectedFullPath, $primaryImg->image_path);
        $this->assertTrue(Storage::disk('public')->exists($expectedFullPath));
        $fullSize = Storage::disk('public')->size($expectedFullPath);
        $this->assertLessThan(200 * 1024, $fullSize);

        // Verify dimensions of full image <= 1200
        $fullData = Storage::disk('public')->get($expectedFullPath);
        $fullImg = imagecreatefromstring($fullData);
        $this->assertLessThanOrEqual(1200, imagesx($fullImg));
        $this->assertLessThanOrEqual(1200, imagesy($fullImg));
        imagedestroy($fullImg);

        // 3. Check blur_image
        $this->assertNotNull($product->blur_image);
        $this->assertStringStartsWith('data:image/webp;base64,', $product->blur_image);
        $this->assertLessThan(10 * 1024, strlen($product->blur_image)); // Well under 10KB

        // 4. Check original file was cleaned up
        $this->assertFalse(Storage::disk('public')->exists($originalPath));
    }

    public function test_create_product_action_initializes_is_main_gallery_image()
    {
        $admin = Admin::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'sanctum');

        $category = Category::create(['name' => 'Skin', 'slug' => 'skin', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'is_active' => true]);

        $uploadedThumbnail = UploadedFile::fake()->image('thumb_upload.jpg', 800, 800);
        $uploadedGallery = UploadedFile::fake()->image('gallery1.jpg', 800, 800);

        $request = StoreProductRequest::create('/admin/products', 'POST', [
            'name' => 'Hydrating Toner',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'toner-default',
                    'price' => 500,
                    'stock' => 10,
                ],
            ],
        ], [], [
            'thumbnail' => $uploadedThumbnail,
            'gallery_images' => [$uploadedGallery],
        ]);

        $action = new CreateProductAction();
        $product = $action->execute($request);

        $product->refresh();

        $this->assertNotNull($product->image);
        $this->assertStringEndsWith('_thumb.webp', $product->image);
        $this->assertNotNull($product->blur_image);

        // Verify that product_images contains 2 images and the first is is_main = true with full webp
        $images = $product->images;
        $this->assertCount(2, $images);
        $this->assertTrue($images->first()->is_main);
        $this->assertStringEndsWith('.webp', $images->first()->image_path);
        $this->assertStringNotContainsString('_thumb.webp', $images->first()->image_path);

        $this->assertFalse($images->last()->is_main);
    }

    public function test_update_product_action_updates_primary_image_and_protects_is_main()
    {
        $admin = Admin::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'sanctum');

        $category = Category::create(['name' => 'Skin', 'slug' => 'skin', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'is_active' => true]);

        $product = Product::create([
            'name' => 'Old Cream',
            'slug' => 'old-cream',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'image' => 'products/thumbnails/old_cream.jpg',
        ]);

        $primary = $product->images()->create([
            'image_path' => 'products/thumbnails/old_cream.jpg',
            'is_main' => true,
        ]);

        $gallery = $product->images()->create([
            'image_path' => 'products/gallery/cream_side.jpg',
            'is_main' => false,
        ]);

        $newThumbnail = UploadedFile::fake()->image('new_cream.jpg', 600, 600);

        // Attempting to remove both gallery and accidentally passing primary image id
        $request = UpdateProductRequest::create("/admin/products/{$product->id}", 'POST', [
            'name' => 'Updated Cream',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'removed_gallery_images' => [$gallery->id, $primary->id],
        ], [], [
            'thumbnail' => $newThumbnail,
        ]);

        $action = new UpdateProductAction();
        $action->execute($product, $request);

        $product->refresh();

        // Primary image record must still exist with updated path
        $this->assertDatabaseHas('product_images', [
            'id' => $primary->id,
            'product_id' => $product->id,
            'is_main' => true,
        ]);

        // Secondary gallery image was removed
        $this->assertDatabaseMissing('product_images', [
            'id' => $gallery->id,
        ]);
    }

    public function test_sync_command_backfills_is_main_and_generates_thumbnails()
    {
        $category = Category::create(['name' => 'Skin', 'slug' => 'skin', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'is_active' => true]);

        $imgPath = 'products/thumbnails/legacy_item.jpg';
        Storage::disk('public')->put($imgPath, $this->createFakeImage(800, 800, 'jpg'));

        $product = Product::create([
            'name' => 'Legacy Product',
            'slug' => 'legacy-product',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'image' => $imgPath,
        ]);

        // Product has no images in product_images yet
        $this->assertCount(0, $product->images);

        // Run sync command with --sync
        $this->artisan('products:sync-thumbnails', ['--sync' => true])
            ->assertExitCode(0);

        $product->refresh();

        // Product should now have primary image in product_images
        $this->assertCount(1, $product->images);
        $this->assertTrue($product->images->first()->is_main);

        // Should have generated _thumb.webp and blur_image
        $this->assertStringEndsWith('_thumb.webp', $product->image);
        $this->assertNotNull($product->blur_image);
    }

    public function test_product_resources_expose_blur_image_and_prioritize_high_res_images()
    {
        $category = Category::create(['name' => 'Skin', 'slug' => 'skin', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'is_active' => true]);

        $product = Product::create([
            'name' => 'Glow Essence',
            'slug' => 'glow-essence',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'image' => 'products/thumbnails/glow_thumb.webp',
            'blur_image' => 'data:image/webp;base64,UklGRm...',
        ]);

        $product->images()->create([
            'image_path' => 'products/thumbnails/glow_full.webp',
            'is_main' => true,
        ]);
        $product->images()->create([
            'image_path' => 'products/gallery/glow_side.webp',
            'is_main' => false,
        ]);

        $product->load(['images', 'category', 'brand', 'variants']);

        // 1. ProductResource (for cards)
        $cardResource = (new ProductResource($product))->toArray(Request::create('/'));
        $this->assertEquals('data:image/webp;base64,UklGRm...', $cardResource['blur_image']);
        $this->assertStringContainsString('glow_thumb.webp', $cardResource['thumbnail']);

        // 2. ProductDetailsResource (for details view)
        $detailResource = (new ProductDetailsResource($product))->toArray(Request::create('/'));
        $this->assertEquals('data:image/webp;base64,UklGRm...', $detailResource['blur_image']);
        $this->assertCount(2, $detailResource['images']);
        // Primary full-res image MUST be first
        $this->assertStringContainsString('glow_full.webp', $detailResource['images'][0]);
        $this->assertStringContainsString('glow_side.webp', $detailResource['images'][1]);
    }
}
