<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
    }

    public function test_authenticated_admin_user_can_update_product_base_fields_and_images()
    {
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

        $product = Product::create([
            'name' => 'Old Product Name',
            'bangla_name' => 'পুরাতন নাম',
            'slug' => 'old-product-slug',
            'description' => 'Old description',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'draft',
            'image' => 'products/thumbnails/old_thumb.jpg',
        ]);

        // Simple default variant
        $variant = $product->variants()->create([
            'sku' => 'old-sku-default-123',
            'price' => 50.00,
            'discount_price' => 45.00,
            'barcode' => 'OLD-BARCODE',
        ]);

        // Gallery image to delete
        $galleryImg = $product->images()->create([
            'image_path' => 'products/gallery/old_gal.jpg',
            'attribute_value' => null,
        ]);

        $newThumbnail = UploadedFile::fake()->image('new_thumbnail.jpg');
        $newGalleryImage = UploadedFile::fake()->image('new_gallery.jpg');

        $payload = [
            'name' => 'Updated Product Name',
            'bangla_name' => 'নতুন নাম',
            'description' => 'New description',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'variants' => [
                [
                    'sku' => $variant->sku,
                    'price' => 150.00,
                    'discount_price' => 120.00,
                    'purchase_price' => 110.00,
                    'barcode' => 'NEW-BARCODE',
                ],
            ],
            'thumbnail' => $newThumbnail,
            'gallery_images' => [$newGalleryImage],
            'removed_gallery_images' => [$galleryImg->id],
        ];

        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);
        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->postJson("/admin/products/{$product->id}", $payload);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $product->refresh();
        $this->assertEquals('Updated Product Name', $product->name);
        $this->assertEquals('New description', $product->description);
        $this->assertEquals('active', $product->status->value ?? $product->status);
        $this->assertNotEquals('products/thumbnails/old_thumb.jpg', $product->image);
        Storage::disk('public')->assertExists($product->image);

        // Check gallery changes (includes 1 primary is_main image and 1 secondary gallery image)
        $this->assertDatabaseMissing('product_images', ['id' => $galleryImg->id]);
        $this->assertCount(1, $product->images->whereNull('attribute_value')->where('is_main', false));
        $this->assertCount(1, $product->images->whereNull('attribute_value')->where('is_main', true));
        $this->assertCount(2, $product->images->whereNull('attribute_value'));
        Storage::disk('public')->assertExists($product->images->whereNull('attribute_value')->first()->image_path);

        // Check default variant updated
        $this->assertCount(1, $product->variants);
        $firstVariant = $product->variants->first();
        $this->assertEquals(150.00, $firstVariant->price);
        $this->assertEquals(120.00, $firstVariant->discount_price);
        $this->assertEquals(110.00, $firstVariant->purchase_price);
        $this->assertEquals('NEW-BARCODE', $firstVariant->barcode);
    }

    public function test_admin_user_can_convert_variant_product_to_single_product()
    {
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

        $product = Product::create([
            'name' => 'Nike Shoes',
            'slug' => 'nike-shoes',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'has_variants' => true,
        ]);

        // Create a variant
        $product->variants()->create([
            'sku' => 'NIKE-SHOE-RED',
            'price' => 100.00,
        ]);

        $payload = [
            'name' => 'Nike Shoes Updated',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'nike-shoe-default',
                    'price' => 90.00,
                    'purchase_price' => 70.00,
                ],
            ],
        ];

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->postJson("/admin/products/{$product->id}", $payload);

        // Assert 200 OK
        $response->assertStatus(200);

        // Assert that the original variant is updated with the new SKU (not soft-deleted)
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'nike-shoe-default', 'price' => 90.00, 'deleted_at' => null]);
        $this->assertCount(1, $product->fresh()->variants);
    }

    public function test_admin_user_updates_variants_and_soft_deletes_omitted()
    {
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

        $colorAttr = Attribute::create([
            'name' => 'Color',
            'type' => 'select',
            'values' => ['Red', 'Blue'],
        ]);

        $product = Product::create([
            'name' => 'Nike Shoes',
            'slug' => 'nike-shoes',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'has_variants' => true,
        ]);

        // Create 2 variants
        $variantRed = $product->variants()->create([
            'sku' => 'NIKE-SHOE-RED',
            'price' => 100.00,
        ]);
        $redVal = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Red']);
        $variantRed->attributeValues()->attach($redVal->id, ['attribute_id' => $colorAttr->id]);

        $variantBlue = $product->variants()->create([
            'sku' => 'NIKE-SHOE-BLUE',
            'price' => 100.00,
        ]);
        $blueVal = AttributeValue::create(['attribute_id' => $colorAttr->id, 'value' => 'Blue']);
        $variantBlue->attributeValues()->attach($blueVal->id, ['attribute_id' => $colorAttr->id]);

        // Update:
        // - NIKE-SHOE-RED is sent in payload
        // - NIKE-SHOE-BLUE is completely omitted from the payload (should become soft deleted)
        $payload = [
            'name' => 'Nike Shoes Updated',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'status' => 'active',
            'variants' => [
                [
                    'id' => $variantRed->id,
                    'sku' => 'NIKE-SHOE-RED',
                    'price' => 120.00,
                    'purchase_price' => 95.00,
                    'options' => [
                        ['attribute_id' => $colorAttr->id, 'value' => 'Red'],
                    ],
                ],
            ],
        ];

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->postJson("/admin/products/{$product->id}", $payload);

        // Assert
        $response->assertStatus(200);

        // Assert that NIKE-SHOE-RED is kept and updated
        $this->assertDatabaseHas('product_variants', ['sku' => 'NIKE-SHOE-RED', 'price' => 120.00, 'purchase_price' => 95.00, 'deleted_at' => null]);
        // Assert that NIKE-SHOE-BLUE is soft deleted
        $this->assertSoftDeleted('product_variants', ['sku' => 'NIKE-SHOE-BLUE']);
        // Assert that its pivot attributes are also soft deleted
        $this->assertSoftDeleted('product_variant_attribute_values', [
            'product_variant_id' => $variantBlue->id,
        ]);
    }
}
