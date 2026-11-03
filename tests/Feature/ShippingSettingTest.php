<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_fetch_public_shipping_settings()
    {
        ShippingSetting::create([
            'inside_dhaka_charge' => 70,
            'outside_dhaka_charge' => 130,
            'is_free_shipping_active' => false,
            'free_shipping_threshold' => 2000,
            'estimated_delivery_inside_dhaka' => '1-2 days',
            'estimated_delivery_outside_dhaka' => '3-4 days',
        ]);

        $response = $this->getJson('/customer/shipping-settings');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.inside_dhaka_charge', 70)
            ->assertJsonPath('data.outside_dhaka_charge', 130)
            ->assertJsonPath('data.is_free_shipping_active', false);
    }

    public function test_admin_can_view_and_update_shipping_settings()
    {
        $admin = Admin::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/admin/shipping-settings');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $updateResponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/admin/shipping-settings', [
                'inside_dhaka_charge' => 80,
                'outside_dhaka_charge' => 140,
                'is_free_shipping_active' => true,
                'free_shipping_threshold' => 1800,
                'estimated_delivery_inside_dhaka' => '24-48 hours',
                'estimated_delivery_outside_dhaka' => '3-5 days',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.inside_dhaka_charge', 80)
            ->assertJsonPath('data.outside_dhaka_charge', 140)
            ->assertJsonPath('data.is_free_shipping_active', true)
            ->assertJsonPath('data.free_shipping_threshold', 1800);

        $this->assertDatabaseHas('shipping_settings', [
            'inside_dhaka_charge' => 80,
            'outside_dhaka_charge' => 140,
            'is_free_shipping_active' => 1,
            'free_shipping_threshold' => 1800,
        ]);
    }

    public function test_order_calculates_district_based_shipping_charge()
    {
        ShippingSetting::create([
            'inside_dhaka_charge' => 60,
            'outside_dhaka_charge' => 120,
            'is_free_shipping_active' => false,
            'free_shipping_threshold' => 1500,
        ]);

        $client = Client::create([
            'name' => 'Test Customer',
            'phone' => '01711000000',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Fashion',
            'slug' => 'fashion',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Saree',
            'slug' => 'saree',
            'category_id' => $category->id,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TEST-SKU-02',
            'price' => 500.00,
            'purchase_price' => 300.00,
            'stock' => 10,
        ]);

        // 1. Order for Dhaka (Banani, Dhaka) -> Should be 60
        $cartDhaka = Cart::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $resDhaka = $this->actingAs($client, 'sanctum')->postJson('/customer/orders', [
            'name' => 'Dhaka Customer',
            'phone' => '01711000001',
            'address' => 'Banani, Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cartDhaka->id],
            'payment_method' => 'cod',
        ]);

        $resDhaka->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'delivery_charge' => 60,
            'grand_total' => 560,
        ]);

        // 2. Order for Outside Dhaka (Tangail) -> Should be 120
        $cartOutside = Cart::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $resOutside = $this->actingAs($client, 'sanctum')->postJson('/customer/orders', [
            'name' => 'Tangail Customer',
            'phone' => '01711000002',
            'address' => 'Tangail Sadar',
            'district' => 'Tangail',
            'cart_ids' => [$cartOutside->id],
            'payment_method' => 'cod',
        ]);

        $resOutside->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'delivery_charge' => 120,
            'grand_total' => 620,
        ]);
    }
}
