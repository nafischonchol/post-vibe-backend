<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PopularProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_popular_products_are_sorted_by_total_sales_quantity()
    {
        $adminUser = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@popular.test',
            'password' => bcrypt('password'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name' => 'Brand',
            'slug' => 'brand',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'is_active' => true,
        ]);

        // Product A: Low sales (5 items)
        $productA = Product::create([
            'name' => 'Product Low Sales',
            'slug' => 'product-low-sales',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        // Product B: High sales (50 items)
        $productB = Product::create([
            'name' => 'Product High Sales',
            'slug' => 'product-high-sales',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $variantA = ProductVariant::create([
            'product_id' => $productA->id,
            'sku' => 'SKU-A',
            'price' => 100,
            'stock' => 100,
        ]);

        $variantB = ProductVariant::create([
            'product_id' => $productB->id,
            'sku' => 'SKU-B',
            'price' => 200,
            'stock' => 100,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-POPULAR-1',
            'total_amount' => 10500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 10500,
            'paid_amount' => 10500,
            'change_amount' => 0,
            'status' => OrderStatusEnum::DELIVERED,
            'created_by_id' => $adminUser->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $productA->id,
            'product_variant_id' => $variantA->id,
            'unit_price' => 100,
            'quantity' => 5,
            'total' => 500,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $productB->id,
            'product_variant_id' => $variantB->id,
            'unit_price' => 200,
            'quantity' => 50,
            'total' => 10000,
        ]);

        $response = $this->getJson('/customer/popular-products');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $items = $response->json('resources');
        $this->assertCount(2, $items);
        $this->assertEquals($productB->id, $items[0]['id']);
        $this->assertEquals($productA->id, $items[1]['id']);
    }

    public function test_popular_products_ignores_sales_older_than_40_days()
    {
        $adminUser = Admin::create([
            'name' => 'Test Admin 2',
            'email' => 'admin2@popular.test',
            'password' => bcrypt('password'),
            'phone' => '01700000001',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'General 2',
            'slug' => 'general-2',
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name' => 'Brand 2',
            'slug' => 'brand-2',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pcs 2',
            'short_name' => 'pcs2',
            'is_active' => true,
        ]);

        $productOldSales = Product::create([
            'name' => 'Product Old Sales',
            'slug' => 'product-old-sales',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $productRecentSales = Product::create([
            'name' => 'Product Recent Sales',
            'slug' => 'product-recent-sales',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $variantOld = ProductVariant::create([
            'product_id' => $productOldSales->id,
            'sku' => 'SKU-OLD',
            'price' => 100,
            'stock' => 100,
        ]);

        $variantRecent = ProductVariant::create([
            'product_id' => $productRecentSales->id,
            'sku' => 'SKU-RECENT',
            'price' => 200,
            'stock' => 100,
        ]);

        // Old Order (50 days ago)
        $oldOrder = Order::create([
            'invoice_no' => 'INV-OLD-1',
            'total_amount' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 10000,
            'paid_amount' => 10000,
            'change_amount' => 0,
            'status' => OrderStatusEnum::DELIVERED,
            'created_by_id' => $adminUser->id,
        ]);
        $oldOrder->created_at = now()->subDays(50);
        $oldOrder->save();

        OrderItem::create([
            'order_id' => $oldOrder->id,
            'product_id' => $productOldSales->id,
            'product_variant_id' => $variantOld->id,
            'unit_price' => 100,
            'quantity' => 100,
            'total' => 10000,
        ]);

        // Recent Order (10 days ago)
        $recentOrder = Order::create([
            'invoice_no' => 'INV-RECENT-1',
            'total_amount' => 2000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 2000,
            'paid_amount' => 2000,
            'change_amount' => 0,
            'status' => OrderStatusEnum::DELIVERED,
            'created_by_id' => $adminUser->id,
        ]);
        $recentOrder->created_at = now()->subDays(10);
        $recentOrder->save();

        OrderItem::create([
            'order_id' => $recentOrder->id,
            'product_id' => $productRecentSales->id,
            'product_variant_id' => $variantRecent->id,
            'unit_price' => 200,
            'quantity' => 10,
            'total' => 2000,
        ]);

        $response = $this->getJson('/customer/popular-products');

        $response->assertStatus(200);
        $items = $response->json('resources');

        $this->assertEquals($productRecentSales->id, $items[0]['id']);
        $this->assertEquals($productOldSales->id, $items[1]['id']);
    }
}
