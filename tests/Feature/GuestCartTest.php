<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestCartTest extends TestCase
{
    use RefreshDatabase;

    private function createProductWithVariant(string $name = 'Test Product'): array
    {
        $category = Category::create([
            'name' => 'Cat '.Str::random(5),
            'slug' => 'cat-'.Str::random(5),
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::random(5),
            'price' => 100,
            'stock' => 50,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }

    private function createClient(): Client
    {
        return Client::create([
            'name' => 'Customer '.Str::random(4),
            'username' => 'user_'.Str::random(5),
            'email' => 'user_'.Str::random(5).'@example.com',
            'phone' => '017'.rand(10000000, 99999999),
            'password' => bcrypt('password'),
            'status' => 'approved',
            'is_active' => true,
        ]);
    }

    public function test_guest_can_add_to_cart_without_variant_id()
    {
        [$product, $variant] = $this->createProductWithVariant('Shampoo');
        $visitorId = (string) Str::ulid();

        $response = $this->withHeader('X-Visitor-Id', $visitorId)
            ->postJson('/customer/cart', [
                'product_id' => $product->id,
                'quantity' => 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'visitor_id' => $visitorId,
            'client_id' => null,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 3,
        ]);
    }

    public function test_guest_can_get_cart_items_via_visitor_id()
    {
        [$product, $variant] = $this->createProductWithVariant('Soap');
        $visitorId = (string) Str::ulid();

        Cart::create([
            'visitor_id' => $visitorId,
            'client_id' => null,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $response = $this->withHeader('X-Visitor-Id', $visitorId)
            ->getJson('/customer/cart');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'resources');
    }

    public function test_cart_merges_on_login_and_overwrites_existing_client_quantity_with_visitor_quantity()
    {
        $client = $this->createClient();
        [$productA, $variantA] = $this->createProductWithVariant('Product A');
        [$productB, $variantB] = $this->createProductWithVariant('Product B');

        $visitorId = (string) Str::ulid();

        // Client already has Product A with quantity 2
        Cart::create([
            'visitor_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'product_id' => $productA->id,
            'product_variant_id' => $variantA->id,
            'quantity' => 2,
        ]);

        // Guest adds Product A (qty 5) and Product B (qty 1) under visitorId
        Cart::create([
            'visitor_id' => $visitorId,
            'client_id' => null,
            'product_id' => $productA->id,
            'product_variant_id' => $variantA->id,
            'quantity' => 5,
        ]);

        Cart::create([
            'visitor_id' => $visitorId,
            'client_id' => null,
            'product_id' => $productB->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 1,
        ]);

        // Log in customer with X-Visitor-Id header
        $response = $this->withHeader('X-Visitor-Id', $visitorId)
            ->postJson('/customer/login', [
                'login' => $client->email,
                'password' => 'password',
            ]);

        $response->assertStatus(200);

        // Verify Product A quantity in client cart became 5 (visitor quantity kept)
        $this->assertDatabaseHas('carts', [
            'client_id' => $client->id,
            'product_id' => $productA->id,
            'product_variant_id' => $variantA->id,
            'quantity' => 5,
        ]);

        // Verify Product B merged to client_id
        $this->assertDatabaseHas('carts', [
            'client_id' => $client->id,
            'product_id' => $productB->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 1,
        ]);

        // Total items in client cart should be 2
        $this->assertEquals(2, Cart::where('client_id', $client->id)->count());
    }
}
