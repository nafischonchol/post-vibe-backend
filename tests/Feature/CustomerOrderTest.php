<?php

namespace Tests\Feature;

use App\Actions\Customer\Order\PlaceCustomerOrderAction;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\ClientOtp;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_place_order_using_cart_ids_and_carts_are_deleted()
    {
        $client = Client::create([
            'name' => 'John Customer',
            'contact_name' => 'John Customer',
            'username' => 'johncustomer',
            'email' => 'john@customer.com',
            'phone' => '01711000000',
            'password' => bcrypt('password'),
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-01',
            'price' => 500.00,
            'purchase_price' => 300.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $payload = [
            'name' => 'John Customer',
            'phone' => '01711000000',
            'address' => 'Dhaka, Bangladesh',
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/customer/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('resources.grand_total', 1000);

        // Verify stock is decremented
        $this->assertEquals(8, $variant->fresh()->stock);

        // Verify cart items are deleted after order placement
        $this->assertDatabaseMissing('carts', [
            'id' => $cart->id,
        ]);
    }

    public function test_customer_can_place_order_using_cart_ids_with_delivery_charge_and_discount()
    {
        $client = Client::create([
            'name' => 'Jane Customer',
            'contact_name' => 'Jane Customer',
            'username' => 'janecustomer',
            'email' => 'jane@customer.com',
            'phone' => '01811000000',
            'password' => bcrypt('password'),
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Skincare',
            'slug' => 'skincare',
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
            'price' => 1200.00,
            'purchase_price' => 800.00,
            'stock' => 5,
        ]);

        $cart = Cart::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $payload = [
            'name' => 'Jane Customer',
            'phone' => '01811000000',
            'address' => 'Banani, Dhaka',
            'city' => 'Dhaka',
            'cart_ids' => [$cart->id],
            'delivery_charge' => 60,
            'discount_amount' => 100,
            'note' => 'Please call before delivery',
            'payment_method' => 'cod',
        ];

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/customer/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('resources.grand_total', 1160); // 1200 - 100 + 60 = 1160

        $this->assertEquals(4, $variant->fresh()->stock);
    }

    public function test_guest_can_place_order_via_public_route()
    {
        $category = Category::create([
            'name' => 'Cosmetics',
            'slug' => 'cosmetics',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Lipstick Red',
            'slug' => 'lipstick-red',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'LIP-01',
            'price' => 650.00,
            'purchase_price' => 400.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $payload = [
            'name' => 'Guest User',
            'phone' => '01999888777',
            'address' => 'Mirpur, Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart->id],
            'delivery_charge' => 60,
            'payment_method' => 'cod',
        ];

        $response = $this->postJson('/customer/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('resources.grand_total', 1360); // 1300 + 60 = 1360

        $this->assertEquals(8, $variant->fresh()->stock);
    }

    public function test_customer_can_place_order_with_their_own_saved_address_id()
    {
        $client = Client::create([
            'name' => 'Owner Client',
            'phone' => '01700112233',
            'status' => 'approved',
        ]);

        $address = ClientAddress::create([
            'client_id' => $client->id,
            'name' => 'Home Address',
            'phone' => '01700112233',
            'address' => 'Gulshan 2, Dhaka',
            'city' => 'Dhaka',
            'label' => 'home',
        ]);

        $category = Category::create([
            'name' => 'Health',
            'slug' => 'health',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Vitamins',
            'slug' => 'vitamins',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VIT-01',
            'price' => 200.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $payload = [
            'address_id' => $address->id,
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/customer/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_cannot_place_order_with_another_clients_address_id()
    {
        $client1 = Client::create([
            'name' => 'Client 1',
            'phone' => '01700112231',
            'status' => 'approved',
        ]);

        $client2 = Client::create([
            'name' => 'Client 2',
            'phone' => '01700112232',
            'status' => 'approved',
        ]);

        $addressOfClient2 = ClientAddress::create([
            'client_id' => $client2->id,
            'name' => 'Client 2 Address',
            'phone' => '01700112232',
            'address' => 'Dhanmondi, Dhaka',
            'city' => 'Dhaka',
        ]);

        $category = Category::create([
            'name' => 'Care',
            'slug' => 'care',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Shampoo',
            'slug' => 'shampoo',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SHP-01',
            'price' => 300.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'client_id' => $client1->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $payload = [
            'address_id' => $addressOfClient2->id, // belongs to client2
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        // Client 1 attempts to use Client 2's address ID
        $response = $this->actingAs($client1, 'sanctum')
            ->postJson('/customer/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['address_id']);
    }

    public function test_guest_cannot_use_address_id()
    {
        $category = Category::create([
            'name' => 'Care',
            'slug' => 'care',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Soap',
            'slug' => 'soap',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SOP-01',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $payload = [
            'address_id' => 99,
            'name' => 'Guest User',
            'phone' => '01900000000',
            'address' => 'Mirpur, Dhaka',
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        $response = $this->postJson('/customer/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['address_id']);
    }

    public function test_guest_can_place_order_with_valid_otp_and_client_is_created_and_verified()
    {
        $category = Category::create([
            'name' => 'Hair Care',
            'slug' => 'hair-care',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Shampoo',
            'slug' => 'shampoo',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SHP-01',
            'price' => 350.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        ClientOtp::generateOtp('01999888111', 'order');

        $payload = [
            'name' => 'OTP Guest',
            'phone' => '01999888111',
            'otp' => '123456',
            'address' => 'Uttara, Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        $response = $this->postJson('/customer/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $createdClient = Client::where('phone', '01999888111')->first();
        $this->assertNotNull($createdClient);
        $this->assertNotNull($createdClient->phone_verified_at);
    }

    public function test_guest_order_fails_with_invalid_otp()
    {
        $category = Category::create([
            'name' => 'Lipstick Care',
            'slug' => 'lipstick-care',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Matte Lipstick',
            'slug' => 'matte-lipstick',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'LIP-MATTE',
            'price' => 450.00,
            'stock' => 10,
        ]);

        $cart = Cart::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        ClientOtp::generateOtp('01999888222', 'order');

        $payload = [
            'name' => 'Bad OTP Guest',
            'phone' => '01999888222',
            'otp' => '999999', // Invalid OTP
            'address' => 'Gulshan, Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        $response = $this->postJson('/customer/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_logged_in_customer_places_order_without_needing_otp()
    {
        $client = Client::create([
            'name' => 'Verified Customer',
            'phone' => '01711223344',
            'status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Perfume',
            'slug' => 'perfume',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Oud Fragrance',
            'slug' => 'oud-fragrance',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'OUD-01',
            'price' => 2500.00,
            'stock' => 5,
        ]);

        $cart = Cart::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $payload = [
            'name' => 'Verified Customer',
            'phone' => '01711223344',
            'address' => 'Dhanmondi, Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart->id],
            'payment_method' => 'cod',
        ];

        // Authenticated client should NOT need any OTP
        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/customer/orders', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_invoice_no_generates_incrementally_without_duplicates()
    {
        $action = new PlaceCustomerOrderAction;

        $today = now()->format('dmY');

        $category = Category::create([
            'name' => 'Lock Test Category',
            'slug' => 'lock-test-cat',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Lock Test Product',
            'slug' => 'lock-test-product',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'LOCK-01',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $cart1 = Cart::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $cart2 = Cart::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $payload1 = [
            'name' => 'Lock Test User',
            'phone' => '01799000111',
            'address' => 'Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart1->id],
            'payment_method' => 'cod',
        ];

        $payload2 = [
            'name' => 'Lock Test User',
            'phone' => '01799000111',
            'address' => 'Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart2->id],
            'payment_method' => 'cod',
        ];

        $order1 = $action->execute($payload1);
        $order2 = $action->execute($payload2);

        $this->assertEquals("INV{$today}-01", $order1->invoice_no);
        $this->assertEquals("INV{$today}-02", $order2->invoice_no);
    }
}
