<?php

namespace Tests\Feature;

use App\Enums\AccountTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreateTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin()
    {
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $this->actingAs($adminUser, 'sanctum');

        return $adminUser;
    }

    public function test_can_create_sale_order_and_sets_status_to_placed()
    {
        $adminUser = $this->authenticateAdmin();

        // Create deps
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
            'name' => 'Samsung Galaxy S23',
            'slug' => 'samsung-galaxy-s23',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'galaxy-s23-default',
            'price' => 1000.00,
            'purchase_price' => 750.00,
            'stock' => 10,
        ]);

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 0.00,
        ]);

        $payload = [
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                    'unit_price' => 1000.00,
                ],
            ],
            'account_id' => $account->id,
            'paid_amount' => 2000.00,
        ];

        $response = $this->postJson('/admin/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'message',
            'resources' => [
                'id',
                'invoice_no',
                'date',
                'items' => [
                    '*' => [
                        'id',
                        'name',
                        'unit_price',
                        'quantity',
                        'total',
                    ],
                ],
                'subtotal',
                'grand_total',
                'paid_amount',
                'change_amount',
            ],
        ]);

        // Verify status in DB is 'placed' (i.e. OrderStatusEnum::PLACED->value)
        $this->assertDatabaseHas('orders', [
            'status' => OrderStatusEnum::PLACED->value,
            'grand_total' => 2000.00,
            'paid_amount' => 2000.00,
        ]);
    }

    public function test_can_update_order_status_with_note_and_history_is_recorded()
    {
        $adminUser = $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 100.00,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-001',
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'grand_total' => 100.00,
            'paid_amount' => 100.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::PLACED->value,
        ]);

        // Change from PLACED to CONFIRMED
        $response = $this->postJson("/admin/orders/{$order->id}/status", [
            'status' => OrderStatusEnum::CONFIRMED->value,
            'note' => 'Confirmed order.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatusEnum::CONFIRMED->value,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status' => OrderStatusEnum::CONFIRMED->value,
            'note' => 'Confirmed order.',
            'changed_by_id' => $adminUser->id,
        ]);
    }

    public function test_cannot_update_order_status_backwards()
    {
        $adminUser = $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 100.00,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-001',
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'grand_total' => 100.00,
            'paid_amount' => 100.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::CONFIRMED->value,
        ]);

        // Attempting to change back to PLACED should be rejected
        $response = $this->postJson("/admin/orders/{$order->id}/status", [
            'status' => OrderStatusEnum::PLACED->value,
            'note' => 'Try to go back.',
        ]);

        $response->assertStatus(422);
    }

    public function test_can_cancel_order_before_shipped()
    {
        $adminUser = $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 100.00,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-001',
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'grand_total' => 100.00,
            'paid_amount' => 100.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::READY_TO_DELIVER->value,
        ]);

        $response = $this->postJson("/admin/orders/{$order->id}/status", [
            'status' => OrderStatusEnum::CANCELLED->value,
            'note' => 'Cancelling this order.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatusEnum::CANCELLED->value,
        ]);
    }

    public function test_cannot_cancel_order_after_shipped()
    {
        $adminUser = $this->authenticateAdmin();

        $account = Account::create([
            'name' => 'Cash Account',
            'type' => AccountTypeEnum::CASH,
            'is_active' => true,
            'balance' => 100.00,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV-001',
            'total_amount' => 100.00,
            'discount_amount' => 0.00,
            'grand_total' => 100.00,
            'paid_amount' => 100.00,
            'change_amount' => 0.00,
            'account_id' => $account->id,
            'status' => OrderStatusEnum::SHIPPED->value,
        ]);

        $response = $this->postJson("/admin/orders/{$order->id}/status", [
            'status' => OrderStatusEnum::CANCELLED->value,
            'note' => 'Try to cancel after shipping.',
        ]);

        $response->assertStatus(422);
    }
}
