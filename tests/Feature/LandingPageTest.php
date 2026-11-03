<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Category $category;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create([
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Mosquito Protection',
            'slug' => 'mosquito-protection',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Electric Mosquito Bat',
            'slug' => 'electric-mosquito-bat',
            'slug_url' => 'electric-mosquito-bat',
            'category_id' => $this->category->id,
            'status' => 'active',
        ]);
    }

    public function test_customer_can_fetch_category_landing_data(): void
    {
        $response = $this->getJson("/customer/category-landing/{$this->category->slug}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.category.slug', 'mosquito-protection')
            ->assertJsonPath('resources.category.name', 'Mosquito Protection');
    }

    public function test_admin_can_list_category_landing_pages(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/admin/landing-pages/categories');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $items = $response->json('resources');
        $this->assertNotEmpty($items);
        $this->assertEquals('Mosquito Protection', $items[0]['name']);
        $this->assertFalse($items[0]['is_customized']);
    }

    public function test_admin_can_update_and_reset_category_landing(): void
    {
        $updatePayload = [
            'landing_headline' => 'Super Mosquito Guard 2026',
            'landing_badge' => 'Special Deal',
            'landing_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'landing_benefits' => [
                ['title' => 'Fast Delivery', 'desc' => 'Across Bangladesh'],
            ],
            'landing_steps' => [
                ['step' => '1', 'title' => 'Select Bat', 'desc' => 'Pick your color'],
            ],
            'faqs' => [
                ['question' => 'How to order?', 'answer' => 'Click order now'],
            ],
        ];

        $updateResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/admin/landing-pages/categories/{$this->category->id}", $updatePayload);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->category->refresh();
        $this->assertEquals('Super Mosquito Guard 2026', $this->category->landing_headline);
        $this->assertEquals('Special Deal', $this->category->landing_badge);
        $this->assertCount(1, $this->category->landing_benefits);
        $this->assertCount(1, $this->category->faqs);

        // Reset
        $resetResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/admin/landing-pages/categories/{$this->category->id}/reset");

        $resetResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->category->refresh();
        $this->assertNull($this->category->landing_headline);
        $this->assertNull($this->category->landing_badge);
        $this->assertNull($this->category->landing_benefits);
        $this->assertNull($this->category->faqs);
    }

    public function test_admin_can_list_product_landing_pages(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/admin/landing-pages/products');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $items = $response->json('resources');
        $this->assertNotEmpty($items);
        $this->assertEquals('Electric Mosquito Bat', $items[0]['name']);
        $this->assertFalse($items[0]['is_customized']);
    }

    public function test_admin_can_update_and_reset_product_landing(): void
    {
        $updatePayload = [
            'landing_headline' => 'Buy 1 Get 1 Free Today',
            'landing_badge' => 'Flash Offer',
            'landing_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'landing_faqs' => [
                ['question' => 'Is it authentic?', 'answer' => '100% original'],
            ],
        ];

        $updateResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/admin/landing-pages/products/{$this->product->id}", $updatePayload);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->product->refresh();
        $this->assertEquals('Buy 1 Get 1 Free Today', $this->product->landing_headline);
        $this->assertCount(1, $this->product->landing_faqs);

        // Reset
        $resetResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/admin/landing-pages/products/{$this->product->id}/reset");

        $resetResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->product->refresh();
        $this->assertNull($this->product->landing_headline);
        $this->assertNull($this->product->landing_faqs);
    }

    public function test_order_placement_executes_landing_tier_offer(): void
    {
        // 1. Create a variant for the product
        $variant = \App\Models\ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'EMB-001',
            'price' => 500.00,
            'stock' => 50,
            'is_active' => true,
        ]);

        // 2. Create a tier offer for the product (Buy 2, ৳100 discount, free shipping)
        $tierOffer = \App\Models\LandingTierOffer::create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'title' => '২টি নিলে বিশেষ অফার',
            'discount_amount' => 100.00,
            'is_free_shipping' => true,
            'gift_text' => 'ফ্রি শিট মাস্ক',
            'is_active' => true,
        ]);

        // 3. Customer adds 2 items to cart
        $cart = \App\Models\Cart::create([
            'client_id' => null,
            'product_id' => $this->product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        // 4. Place order with landing_offer_id
        $response = $this->postJson('/customer/orders', [
            'name' => 'John Doe',
            'phone' => '01712345678',
            'address' => 'Mirpur, Dhaka',
            'district' => 'Dhaka',
            'cart_ids' => [$cart->id],
            'landing_offer_id' => $tierOffer->id,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $orderId = $response->json('resources.order_id');
        $order = \App\Models\Order::find($orderId);

        $this->assertNotNull($order);
        $this->assertEquals($tierOffer->id, $order->landing_offer_id);
        $this->assertEquals(100.00, (float) $order->discount_amount);
        $this->assertEquals(0.00, (float) $order->delivery_charge); // Free shipping executed!
        $this->assertEquals(900.00, (float) $order->grand_total); // (500 * 2) - 100 + 0 = 900
        $this->assertEquals('২টি নিলে বিশেষ অফার', $order->offer_snapshot['title']);
    }

    public function test_admin_can_add_multiple_shoppable_landing_videos_and_customer_can_view_them(): void
    {
        $videoPayload = [
            'landing_videos' => [
                [
                    'video_url' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ',
                    'title' => 'কাস্টমার রিয়েল রিভিউ',
                    'badge_text' => 'APLB',
                    'featured_product_id' => $this->product->id,
                    'sort_order' => 1,
                ],
                [
                    'video_url' => 'https://youtu.be/kJQP7kiw5Fk',
                    'title' => 'ব্যবহারবিধি ও ফলাফল',
                    'badge_text' => 'AROMATICA',
                    'featured_product_id' => $this->product->id,
                    'sort_order' => 2,
                ],
            ],
        ];

        // 1. Admin saves videos for category landing
        $catResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/admin/landing-pages/categories/{$this->category->id}", $videoPayload);

        $catResponse->assertStatus(200);

        // 2. Customer fetches category landing data and receives videos
        $customerCatRes = $this->getJson("/customer/category-landing/{$this->category->slug}");
        $customerCatRes->assertStatus(200);
        $videos = $customerCatRes->json('resources.category.landing_videos');

        $this->assertCount(2, $videos);
        $this->assertEquals('dQw4w9WgXcQ', $videos[0]['video_id']);
        $this->assertEquals('APLB', $videos[0]['badge_text']);
        $this->assertEquals($this->product->id, $videos[0]['featured_product']['id']);

        // 3. Admin saves videos for product landing
        $prodResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/admin/landing-pages/products/{$this->product->id}", $videoPayload);

        $prodResponse->assertStatus(200);

        // 4. Customer fetches product landing details
        $customerProdRes = $this->getJson("/customer/product/{$this->product->slug_url}");
        $customerProdRes->assertStatus(200);
        $prodVideos = $customerProdRes->json('resources.landing_videos');

        $this->assertCount(2, $prodVideos);
        $this->assertEquals('dQw4w9WgXcQ', $prodVideos[0]['video_id']);
        $this->assertEquals('APLB', $prodVideos[0]['badge_text']);
    }
}
