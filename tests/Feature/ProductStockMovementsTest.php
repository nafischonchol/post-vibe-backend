<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Enums\StockMovementType;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockMovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_user_can_fetch_stock_movements()
    {
        // 1. Create dependencies
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john.mov@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567800',
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

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'weight' => 0.5,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'test-sku',
            'price' => 100,
            'stock' => 10,
        ]);

        // Create stock movement
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovementType::ADJUSTMENT,
            'quantity' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
            'reason' => 'Initial stock load',
            'created_by_id' => $adminUser->id,
        ]);

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->getJson("/admin/products/{$product->id}/stock-movements");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Stock movements retrieved successfully');

        $movements = $response->json('resources.data');
        $this->assertNotNull($movements);
        $this->assertCount(1, $movements);
        $this->assertEquals($movement->id, $movements[0]['id']);
        $this->assertEquals('test-sku', $movements[0]['variant']['sku']);
        $this->assertEquals('John Admin', $movements[0]['creator']['name']);
    }

    public function test_authenticated_admin_user_can_fetch_stock_movements_with_order_item_reference()
    {
        // 1. Create dependencies
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john.mov2@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567809',
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

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'weight' => 0.5,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'test-sku',
            'price' => 100,
            'stock' => 10,
        ]);

        // Create Order
        $order = Order::create([
            'invoice_no' => 'INV-TEST-123',
            'total_amount' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 100,
            'paid_amount' => 100,
            'change_amount' => 0,
            'status' => OrderStatusEnum::PLACED,
            'created_by_id' => $adminUser->id,
        ]);

        // Create Order Item
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_snapshot' => [
                'name' => $product->name,
                'sku' => $variant->sku,
            ],
            'unit_price' => 100,
            'quantity' => 1,
            'total' => 100,
        ]);

        // Create stock movement referencing OrderItem
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => StockMovementType::SALE,
            'quantity' => -1,
            'stock_before' => 10,
            'stock_after' => 9,
            'reference_type' => OrderItem::class,
            'reference_id' => $orderItem->id,
            'reason' => 'Sale order INV-TEST-123',
            'created_by_id' => $adminUser->id,
        ]);

        // Act
        $response = $this->actingAs($adminUser, 'sanctum')
            ->getJson("/admin/products/{$product->id}/stock-movements");

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Stock movements retrieved successfully');

        $movements = $response->json('resources.data');
        $this->assertNotNull($movements);
        $this->assertCount(1, $movements);
        $this->assertEquals($movement->id, $movements[0]['id']);
        $this->assertEquals('test-sku', $movements[0]['variant']['sku']);
        $this->assertEquals('John Admin', $movements[0]['creator']['name']);

        // Assert that the order is loaded inside the reference
        $this->assertNotNull($movements[0]['reference']['order']);
        $this->assertEquals('INV-TEST-123', $movements[0]['reference']['order']['invoice_no']);
    }
}
