<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderListTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_fetch_order_list_and_details()
    {
        $client = Client::create([
            'name' => 'John OrderTest',
            'contact_name' => 'John OrderTest',
            'username' => 'johnordertest',
            'email' => 'johnordertest@customer.com',
            'phone' => '01711999999',
            'password' => bcrypt('password'),
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Skin Care',
            'slug' => 'skin-care',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Snail Essence',
            'slug' => 'snail-essence',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SNAIL-01',
            'price' => 150.00,
            'stock' => 50,
        ]);

        $order = Order::create([
            'invoice_no' => 'INV15082026-01',
            'client_id' => $client->id,
            'client_snapshot' => [
                'name' => $client->name,
                'phone' => $client->phone,
                'address' => 'Test Address',
                'city' => 'Dhaka',
            ],
            'total_amount' => 300.00,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 300.00,
            'paid_amount' => 300.00,
            'status' => OrderStatusEnum::PLACED->value,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_snapshot' => [
                'name' => 'Snail Essence',
                'sku' => 'SNAIL-01',
                'variant_title' => '100ml',
            ],
            'unit_price' => 150.00,
            'quantity' => 2,
            'total' => 300.00,
        ]);

        // Fetch Order List
        $response = $this->actingAs($client, 'sanctum')
            ->getJson('/customer/me/orders');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'resources');
        $response->assertJsonPath('resources.0.invoice_no', 'INV15082026-01');
        $response->assertJsonPath('resources.0.items_count', 1);

        // Fetch Order Detail by ID
        $detailResponse = $this->actingAs($client, 'sanctum')
            ->getJson('/customer/me/orders/'.$order->id);

        $detailResponse->assertStatus(200);
        $detailResponse->assertJsonPath('success', true);
        $detailResponse->assertJsonPath('resources.invoice_no', 'INV15082026-01');
        $detailResponse->assertJsonPath('resources.grand_total', 300);
    }
}
