<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductEditPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
    }

    public function test_authenticated_admin_user_can_fetch_edit_payload_for_simple_product()
    {
        // 1. Create admin user
        $adminUser = Admin::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
            'phone' => '1234567890',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pieces',
            'short_name' => 'pcs',
            'is_active' => true,
        ]);

        // Create product
        $product = Product::create([
            'name' => 'Samsung Galaxy S23',
            'bangla_name' => 'স্যামসাং গ্যালাক্সি এস২৩',
            'slug' => 'samsung-galaxy-s23-12345',
            'description' => 'A great phone',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'image' => 'products/thumbnails/phone.jpg',
            'youtube_video_urls' => ['https://www.youtube.com/watch?v=123'],
            'authenticity_video_url' => 'https://www.youtube.com/watch?v=auth123',
            'weight' => 0.2,
        ]);

        // Simple products have one default variant
        $variant = $product->variants()->create([
            'sku' => 'galaxy-s23-default-xyz',
            'price' => 999.99,
            'discount_price' => 899.99,
            'purchase_price' => 750.00,
            'barcode' => '123456789',
            'stock' => 10,
        ]);

        // Add a gallery image
        $product->images()->create([
            'image_path' => 'products/gallery/phone_side.jpg',
            'attribute_value' => null,
        ]);

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->getJson("/admin/products/{$product->id}/edit-payload");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'resources' => [
                'id',
                'name',
                'bangla_name',
                'description',
                'category_id',
                'brand_id',
                'unit_id',
                'youtube_video_urls',
                'authenticity_video_url',
                'weight',
                'status',
                'thumbnail_url',
                'thumbnail_relative',
                'has_variants',
                'gallery_images' => [
                    '*' => [
                        'id',
                        'image_relative',
                        'image_url',
                    ],
                ],
                'specifications',
                'variant_attributes',
                'variants',
            ],
        ]);

        $data = $response->json('resources');
        $this->assertEquals('Samsung Galaxy S23', $data['name']);
        $this->assertEquals($category->id, $data['category_id']);
        $this->assertFalse($data['has_variants']);
        $this->assertCount(1, $data['gallery_images']);
        $this->assertEquals('products/gallery/phone_side.jpg', $data['gallery_images'][0]['image_relative']);
        $this->assertStringContainsString('/storage/products/gallery/phone_side.jpg', $data['gallery_images'][0]['image_url']);

        // Verify the simple product variant is in the variants array
        $this->assertCount(1, $data['variants']);
        $this->assertEquals('999.99', $data['variants'][0]['price']);
        $this->assertEquals('899.99', $data['variants'][0]['discount_price']);
        $this->assertEquals('750.00', $data['variants'][0]['purchase_price']);
        $this->assertEquals('123456789', $data['variants'][0]['barcode']);
    }

    public function test_authenticated_admin_user_can_fetch_edit_payload_for_variant_product()
    {
        // 1. Create admin user
        $adminUser = Admin::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
            'phone' => '1234567890',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Clothing',
            'slug' => 'clothing',
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
            'values' => ['Red', 'Blue', 'Green'],
        ]);

        $sizeAttr = Attribute::create([
            'name' => 'Size',
            'type' => 'select',
            'values' => ['S', 'M', 'L'],
        ]);

        $materialAttr = Attribute::create([
            'name' => 'Material',
            'type' => 'text',
        ]);

        // Create product
        $product = Product::create([
            'name' => 'Nike T-Shirt',
            'bangla_name' => 'নাইকি টি-শার্ট',
            'slug' => 'nike-t-shirt-12345',
            'description' => 'A cool t-shirt',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        // Add specifications
        $specMaterial = $product->specifications()->create([
            'attribute_id' => $materialAttr->id,
            'custom_value' => 'Cotton 100%',
        ]);

        $specColor = $product->specifications()->create([
            'attribute_id' => $colorAttr->id,
            'custom_value' => null,
        ]);
        $redVal = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Red']);
        $specColor->predefinedValues()->attach($redVal->id);

        // Add variant: Red / S
        $variantRedS = $product->variants()->create([
            'sku' => 'nike-red-s',
            'price' => 25.00,
            'discount_price' => 20.00,
            'purchase_price' => 18.50,
            'barcode' => 'BARCODE-RED-S',
        ]);
        $sVal = AttributeValue::create(['attribute_id' => $sizeAttr->id, 'value' => 'S']);

        $variantRedS->attributeValues()->attach($redVal->id, ['attribute_id' => $colorAttr->id]);
        $variantRedS->attributeValues()->attach($sVal->id, ['attribute_id' => $sizeAttr->id]);

        // Add color variant image for 'Red'
        $product->images()->create([
            'image_path' => 'products/variants/shirt_red.jpg',
            'attribute_value' => 'Red',
        ]);

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->getJson("/admin/products/{$product->id}/edit-payload");

        // Assert
        $response->assertStatus(200);
        $data = $response->json('resources');

        $this->assertTrue($data['has_variants']);
        $this->assertArrayNotHasKey('price', $data); // price is no longer at top level

        // Verify variant attributes structure
        $this->assertCount(2, $data['variant_attributes']);
        $colors = collect($data['variant_attributes'])->firstWhere('name', 'Color');
        $this->assertNotNull($colors);
        $this->assertEquals(['Red'], $colors['selectedValues']);
        $this->assertEquals(['Red', 'Blue', 'Green'], $colors['values']);

        // Verify specifications structure
        $this->assertCount(2, $data['specifications']);
        $materialSpec = collect($data['specifications'])->firstWhere('name', 'Material');
        $this->assertEquals('Cotton 100%', $materialSpec['value']);

        $colorSpec = collect($data['specifications'])->firstWhere('name', 'Color');
        $this->assertEquals('Red', $colorSpec['value']);

        // Verify variant combos list
        $this->assertCount(1, $data['variants']);
        $vData = $data['variants'][0];
        $this->assertEquals('nike-red-s', $vData['sku']);
        $this->assertEquals('25.00', $vData['price']);
        $this->assertEquals('20.00', $vData['discount_price']);
        $this->assertEquals('18.50', $vData['purchase_price']);
        $this->assertEquals('BARCODE-RED-S', $vData['barcode']);
        $this->assertEquals('Red / S', $vData['title']);

        // Verify color images mapping
        $this->assertEquals('products/variants/shirt_red.jpg', $data['color_images']['Red']);
        $this->assertStringContainsString('/storage/products/variants/shirt_red.jpg', $data['color_images_previews']['Red']);
    }
}
