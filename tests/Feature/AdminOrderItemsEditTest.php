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

class AdminOrderItemsEditTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private string $adminToken;
    private Product $product;
    private ProductVariant $variant1;
    private ProductVariant $variant2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->adminToken = $this->admin->createToken('admin_token')->plainTextToken;

        $category = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);
        $brand = Brand::create(['name' => 'BrandX', 'slug' => 'brandx', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'pcs', 'is_active' => true]);

        $this->product = Product::create([
            'name' => 'Premium Shirt',
            'slug' => 'premium-shirt',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->variant1 = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'SHIRT-BLUE-M',
            'price' => 500.00,
            'purchase_price' => 300.00,
            'stock' => 10,
        ]);

        $this->variant2 = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'SHIRT-RED-L',
            'price' => 600.00,
            'purchase_price' => 350.00,
            'stock' => 5,
        ]);
    }

    public function test_can_increase_and_decrease_order_item_quantities_with_proper_stock_movements(): void
    {
        $order = Order::create([
            'invoice_no' => 'INV-TEST-001',
            'status' => OrderStatusEnum::PLACED,
            'total_amount' => 2800.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'delivery_charge' => 100.00,
            'grand_total' => 2900.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant1->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant1->sku, 'variant_title' => 'Blue / M'],
            'unit_price' => 500.00,
            'quantity' => 2,
            'total' => 1000.00,
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant2->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant2->sku, 'variant_title' => 'Red / L'],
            'unit_price' => 600.00,
            'quantity' => 3,
            'total' => 1800.00,
        ]);

        // Request: increase item1 from 2 to 4 (diff +2), decrease item2 from 3 to 1 (diff -2)
        $payload = [
            'note' => 'Customer requested quantity adjustment',
            'items' => [
                [
                    'order_item_id' => $item1->id,
                    'product_variant_id' => $this->variant1->id,
                    'quantity' => 4,
                    'unit_price' => 500.00,
                ],
                [
                    'order_item_id' => $item2->id,
                    'product_variant_id' => $this->variant2->id,
                    'quantity' => 1,
                    'unit_price' => 600.00,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$order->id}/items", $payload);

        $response->assertStatus(200);

        // Verify Variant 1 stock decreased by 2 (10 - 2 = 8)
        $this->assertEquals(8, $this->variant1->fresh()->stock);

        // Verify Variant 2 stock increased by 2 (5 + 2 = 7)
        $this->assertEquals(7, $this->variant2->fresh()->stock);

        // Verify StockMovement for increase is SALE
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $this->variant1->id,
            'type' => StockMovementType::SALE->value,
            'quantity' => 2,
        ]);

        // Verify StockMovement for decrease is ORDER_EDIT_RESTOCK
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $this->variant2->id,
            'type' => StockMovementType::ORDER_EDIT_RESTOCK->value,
            'quantity' => 2,
        ]);

        // Verify order totals: (4 * 500) + (1 * 600) = 2600 + 100 delivery = 2700
        $freshOrder = $order->fresh();
        $this->assertEquals(2600.00, (float) $freshOrder->total_amount);
        $this->assertEquals(2700.00, (float) $freshOrder->grand_total);

        // Verify status history audit note was added
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'changed_by_id' => $this->admin->id,
        ]);
    }

    public function test_removing_item_restocks_inventory_with_order_edit_restock(): void
    {
        $order = Order::create([
            'invoice_no' => 'INV-TEST-002',
            'status' => OrderStatusEnum::CONFIRMED,
            'total_amount' => 1100.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'delivery_charge' => 0.00,
            'grand_total' => 1100.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant1->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant1->sku],
            'unit_price' => 500.00,
            'quantity' => 1,
            'total' => 500.00,
        ]);

        $item2 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant2->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant2->sku],
            'unit_price' => 600.00,
            'quantity' => 1,
            'total' => 600.00,
        ]);

        // Remove item 2 by sending only item 1
        $payload = [
            'items' => [
                [
                    'order_item_id' => $item1->id,
                    'product_variant_id' => $this->variant1->id,
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$order->id}/items", $payload);

        $response->assertStatus(200);

        // Item 2 should be deleted
        $this->assertDatabaseMissing('order_items', ['id' => $item2->id]);

        // Variant 2 stock should be restored by 1 (5 + 1 = 6)
        $this->assertEquals(6, $this->variant2->fresh()->stock);

        // StockMovement must be ORDER_EDIT_RESTOCK
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $this->variant2->id,
            'type' => StockMovementType::ORDER_EDIT_RESTOCK->value,
            'quantity' => 1,
        ]);

        $this->assertEquals(500.00, (float) $order->fresh()->grand_total);
    }

    public function test_can_add_new_item_to_order(): void
    {
        $order = Order::create([
            'invoice_no' => 'INV-TEST-003',
            'status' => OrderStatusEnum::PACKAGING,
            'total_amount' => 500.00,
            'discount_amount' => 0.00,
            'tax_amount' => 0.00,
            'delivery_charge' => 0.00,
            'grand_total' => 500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant1->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant1->sku],
            'unit_price' => 500.00,
            'quantity' => 1,
            'total' => 500.00,
        ]);

        // Add variant2 as a new item (no order_item_id)
        $payload = [
            'items' => [
                [
                    'order_item_id' => $item1->id,
                    'product_variant_id' => $this->variant1->id,
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ],
                [
                    'product_variant_id' => $this->variant2->id,
                    'quantity' => 2,
                    'unit_price' => 600.00,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$order->id}/items", $payload);

        $response->assertStatus(200);

        // Variant 2 stock decremented by 2 (5 - 2 = 3)
        $this->assertEquals(3, $this->variant2->fresh()->stock);

        // Stock movement SALE created
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $this->variant2->id,
            'type' => StockMovementType::SALE->value,
            'quantity' => 2,
        ]);

        $this->assertEquals(1700.00, (float) $order->fresh()->grand_total);
    }

    public function test_cannot_edit_when_status_is_shipped_or_cancelled(): void
    {
        $shippedOrder = Order::create([
            'invoice_no' => 'INV-TEST-SHIPPED',
            'status' => OrderStatusEnum::SHIPPED,
            'total_amount' => 500.00,
            'grand_total' => 500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $payload = [
            'items' => [
                [
                    'product_variant_id' => $this->variant1->id,
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ],
            ],
        ];

        $res1 = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$shippedOrder->id}/items", $payload);
        $res1->assertStatus(422);

        $cancelledOrder = Order::create([
            'invoice_no' => 'INV-TEST-CANCELLED',
            'status' => OrderStatusEnum::CANCELLED,
            'total_amount' => 500.00,
            'grand_total' => 500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$cancelledOrder->id}/items", $payload);
        $res2->assertStatus(422);
    }

    public function test_cannot_increase_quantity_beyond_available_stock(): void
    {
        $order = Order::create([
            'invoice_no' => 'INV-TEST-STOCK',
            'status' => OrderStatusEnum::READY_TO_DELIVER,
            'total_amount' => 500.00,
            'grand_total' => 500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant1->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant1->sku],
            'unit_price' => 500.00,
            'quantity' => 1,
            'total' => 500.00,
        ]);

        // Variant1 stock is 10. Trying to increase by 20 (new qty 21)
        $payload = [
            'items' => [
                [
                    'order_item_id' => $item1->id,
                    'product_variant_id' => $this->variant1->id,
                    'quantity' => 21,
                    'unit_price' => 500.00,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$order->id}/items", $payload);

        $response->assertStatus(500);
        $this->assertStringContainsString('Insufficient stock', $response->json('message'));
    }

    public function test_courier_booking_alert_included_in_audit_note_when_parcel_completed(): void
    {
        $order = Order::create([
            'invoice_no' => 'INV-TEST-COURIER',
            'status' => OrderStatusEnum::READY_TO_DELIVER,
            'parcel_booking_status' => 'completed',
            'total_amount' => 500.00,
            'grand_total' => 500.00,
            'paid_amount' => 0.00,
            'change_amount' => 0.00,
        ]);

        $item1 = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant1->id,
            'product_snapshot' => ['name' => 'Premium Shirt', 'sku' => $this->variant1->sku],
            'unit_price' => 500.00,
            'quantity' => 1,
            'total' => 500.00,
        ]);

        $payload = [
            'items' => [
                [
                    'order_item_id' => $item1->id,
                    'product_variant_id' => $this->variant1->id,
                    'quantity' => 2,
                    'unit_price' => 500.00,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->adminToken)
            ->putJson("/admin/orders/{$order->id}/items", $payload);

        $response->assertStatus(200);

        $latestHistory = $order->statusHistories()->latest('id')->first();
        $this->assertNotNull($latestHistory);
        $this->assertStringContainsString('Courier Alert', $latestHistory->note);
    }
}
