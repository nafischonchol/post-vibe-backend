<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Configure default filesystem disk to public for upload testing
        config(['filesystems.default' => 'public']);
    }

    public function test_can_create_simple_product_without_variants()
    {
        // 1. Create dependencies
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name' => 'Samsung',
            'slug' => 'samsung',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'pcs',
            'is_active' => true,
        ]);

        $thumbnail = UploadedFile::fake()->image('thumbnail.jpg');

        $payload = [
            'name' => 'Samsung Galaxy S23',
            'bangla_name' => 'স্যামসাং গ্যালাক্সি এস২৩',
            'description' => 'A great phone',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'weight' => 0.2,
            'status' => 'active',
            'thumbnail' => $thumbnail,
            'variants' => [
                [
                    'sku' => 'galaxy-s23-default',
                    'price' => 999.99,
                    'discount_price' => 899.99,
                    'purchase_price' => 750.00,
                    'barcode' => '123456789',
                ],
            ],
            'youtube_urls' => ['https://www.youtube.com/watch?v=123', 'https://www.youtube.com/watch?v=456'],
        ];

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->postJson('/admin/products', $payload);

        // Assert
        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('products', [
            'name' => 'Samsung Galaxy S23',
            'bangla_name' => 'স্যামসাং গ্যালাক্সি এস২৩',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'weight' => 0.2,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $product = Product::where('name', 'Samsung Galaxy S23')->first();
        $this->assertNotNull($product->image);
        Storage::disk('public')->assertExists($product->image);
        $this->assertEquals(['https://www.youtube.com/watch?v=123', 'https://www.youtube.com/watch?v=456'], $product->youtube_video_urls);

        // Check default variant
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'galaxy-s23-default',
            'price' => 999.99,
            'discount_price' => 899.99,
            'barcode' => '123456789',
        ]);
    }

    public function test_can_create_product_with_variants_and_specifications()
    {
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john2@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567892',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Clothing',
            'slug' => 'clothing',
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name' => 'Nike',
            'slug' => 'nike',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'pcs',
            'is_active' => true,
        ]);

        // Create attributes
        $colorAttr = Attribute::create([
            'name' => 'Color',
            'type' => 'select',
            'values' => ['Red', 'Blue'],
        ]);

        $sizeAttr = Attribute::create([
            'name' => 'Size',
            'type' => 'select',
            'values' => ['M', 'L'],
        ]);

        $materialAttr = Attribute::create([
            'name' => 'Material',
            'type' => 'text',
        ]);

        $thumbnail = UploadedFile::fake()->image('shirt.jpg');
        $galleryImage1 = UploadedFile::fake()->image('shirt_back.jpg');
        $galleryImage2 = UploadedFile::fake()->image('shirt_folded.jpg');
        $redImage = UploadedFile::fake()->image('shirt_red.jpg');
        $seoImage = UploadedFile::fake()->image('seo.jpg');

        $payload = [
            'name' => 'Nike Sports T-Shirt',
            'bangla_name' => 'নাইকি স্পোর্টস টি-শার্ট',
            'description' => 'Comfortable dry-fit t-shirt',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'weight' => 0.35,
            'status' => 'active',
            'thumbnail' => $thumbnail,
            'meta_image' => $seoImage,
            'gallery_images' => [$galleryImage1, $galleryImage2],
            'specifications' => [
                ['id' => $materialAttr->id, 'value' => '100% Polyester'],
                ['id' => $colorAttr->id, 'value' => 'Red'],
            ],

            // Variants definitions
            'variant_options' => [
                ['id' => $colorAttr->id, 'name' => 'Color', 'selectedValues' => ['Red', 'Blue']],
                ['id' => $sizeAttr->id, 'name' => 'Size', 'selectedValues' => ['M', 'L']],
            ],
            'variants' => [
                [
                    'sku' => 'NIKE-SHIRT-RED-M',
                    'price' => 29.99,
                    'discount_price' => 24.99,
                    'purchase_price' => 18.00,
                    'barcode' => 'RED-M-BARCODE',
                    'options' => [
                        ['attribute_id' => $colorAttr->id, 'value' => 'Red'],
                        ['attribute_id' => $sizeAttr->id, 'value' => 'M'],
                    ],
                ],
                [
                    'sku' => 'NIKE-SHIRT-BLUE-L',
                    'price' => 32.99,
                    'purchase_price' => 20.00,
                    'barcode' => 'BLUE-L-BARCODE',
                    'options' => [
                        ['attribute_id' => $colorAttr->id, 'value' => 'Blue'],
                        ['attribute_id' => $sizeAttr->id, 'value' => 'L'],
                    ],
                ],
            ],

            // Color variant images
            'color_images' => [
                'Red' => $redImage,
            ],
            'youtube_urls' => ['https://www.youtube.com/watch?v=789'],
        ];

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->postJson('/admin/products', $payload);

        // Assert
        $response->assertStatus(201);

        $product = Product::where('name', 'Nike Sports T-Shirt')->first();
        $this->assertNotNull($product);
        $this->assertEquals(['https://www.youtube.com/watch?v=789'], $product->youtube_video_urls);
        $this->assertEquals(0.35, $product->weight);
        $this->assertEquals($adminUser->id, $product->created_by_id);
        $this->assertEquals($adminUser->id, $product->updated_by_id);

        // Assert specifications
        $this->assertDatabaseHas('product_specifications', [
            'product_id' => $product->id,
            'attribute_id' => $materialAttr->id,
            'custom_value' => '100% Polyester',
        ]);

        // Assert variants
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'NIKE-SHIRT-RED-M',
            'price' => 29.99,
            'discount_price' => 24.99,
            'purchase_price' => 18.00,
            'barcode' => 'RED-M-BARCODE',
            'stock' => 0,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'NIKE-SHIRT-BLUE-L',
            'price' => 32.99,
            'purchase_price' => 20.00,
            'barcode' => 'BLUE-L-BARCODE',
            'stock' => 0,
        ]);

        // Check color variant image mapping
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'attribute_value' => 'Red',
        ]);

        $this->assertNotNull($product->meta_image);
        Storage::disk('public')->assertExists($product->meta_image);
    }
}
