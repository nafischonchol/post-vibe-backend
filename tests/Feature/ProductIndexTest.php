<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_paginated_products()
    {
        $admin = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        for ($i = 1; $i <= 25; $i++) {
            Product::create([
                'name' => "Product {$i}",
                'slug' => "product-{$i}",
                'category_id' => $category->id,
                'status' => 'active',
                'has_variants' => false,
            ]);
        }

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/admin/products?page=1&per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'resources',
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertEquals(1, $response->json('pagination.current_page'));
        $this->assertEquals(10, $response->json('pagination.per_page'));
        $this->assertEquals(25, $response->json('pagination.total'));
        $this->assertEquals(3, $response->json('pagination.last_page'));
        $this->assertCount(10, $response->json('resources'));
    }

    public function test_admin_can_search_products_in_index()
    {
        $admin = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general',
        ]);

        Product::create([
            'name' => 'Unique Gadget Alpha',
            'slug' => 'unique-gadget-alpha',
            'category_id' => $category->id,
            'status' => 'active',
            'has_variants' => false,
        ]);

        Product::create([
            'name' => 'Other Product Beta',
            'slug' => 'other-product-beta',
            'category_id' => $category->id,
            'status' => 'active',
            'has_variants' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/admin/products?search=Gadget');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('pagination.total'));
        $this->assertEquals('Unique Gadget Alpha', $response->json('resources.0.name'));
    }
}
