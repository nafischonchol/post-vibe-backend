<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_brand_products_with_pagination()
    {
        $adminUser = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@brandtest.com',
            'password' => bcrypt('password'),
            'phone' => '01700000099',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Skincare',
            'slug' => 'skincare',
            'is_active' => true,
        ]);

        $brand1 = Brand::create([
            'name' => 'Brand A',
            'slug' => 'brand-a',
            'is_active' => true,
        ]);

        $brand2 = Brand::create([
            'name' => 'Brand B',
            'slug' => 'brand-b',
            'is_active' => true,
        ]);

        $unit = Unit::create([
            'name' => 'Pcs',
            'short_name' => 'pcs',
            'is_active' => true,
        ]);

        // Create 3 active products for brand 1
        for ($i = 1; $i <= 3; $i++) {
            Product::create([
                'name' => "Brand 1 Product {$i}",
                'slug' => "brand-1-product-{$i}",
                'status' => 'active',
                'category_id' => $category->id,
                'brand_id' => $brand1->id,
                'unit_id' => $unit->id,
                'created_by_id' => $adminUser->id,
                'updated_by_id' => $adminUser->id,
            ]);
        }

        // Create 1 product for brand 2
        Product::create([
            'name' => 'Brand 2 Product 1',
            'slug' => 'brand-2-product-1',
            'status' => 'active',
            'category_id' => $category->id,
            'brand_id' => $brand2->id,
            'unit_id' => $unit->id,
            'created_by_id' => $adminUser->id,
            'updated_by_id' => $adminUser->id,
        ]);

        $response = $this->getJson("/customer/brand/{$brand1->id}/products?per_page=2&page=1");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $items = $response->json('resources');
        $pagination = $response->json('pagination');

        $this->assertCount(2, $items);
        $this->assertEquals(3, $pagination['total']);
        $this->assertEquals(2, $pagination['per_page']);
        $this->assertEquals(1, $pagination['current_page']);
    }
}
