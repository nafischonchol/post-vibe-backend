<?php

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupAdminUser()
{
    $admin = Admin::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
        'phone' => '01700000000',
        'is_active' => true,
    ]);

    test()->actingAs($admin, 'sanctum');

    return $admin;
}

test('admin can create stock adjustment using adjustment service with DB transaction', function () {
    $admin = setupAdminUser();

    $category = Category::create([
        'name' => 'Electronics',
        'slug' => 'electronics',
        'is_active' => true,
    ]);

    $product = Product::create([
        'name' => 'Test Item',
        'slug' => 'test-item',
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'TEST-SKU-1',
        'price' => 100.00,
        'purchase_price' => 70.00,
        'stock' => 10,
    ]);

    $response = $this->postJson('/admin/adjustments', [
        'product_variant_id' => $variant->id,
        'type' => 'addition',
        'quantity' => 5,
        'unit_price' => 120.00,
        'purchase_price' => 80.00,
        'reason' => 'Inventory count update',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('adjustments', [
        'product_variant_id' => $variant->id,
        'type' => 'addition',
        'quantity' => 5,
        'reason' => 'Inventory count update',
    ]);

    $variant->refresh();
    expect($variant->price)->toEqual(120.00);
    expect($variant->purchase_price)->toEqual(80.00);
});
