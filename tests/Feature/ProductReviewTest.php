<?php

namespace Tests\Feature;

use App\Enums\OrderStatusEnum;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(): Product
    {
        $category = Category::create([
            'name' => 'Skincare',
            'slug' => 'skincare',
            'status' => 'active',
        ]);

        return Product::create([
            'name' => 'Snail Mucin 96 Essence',
            'slug' => 'snail-mucin-96-essence',
            'category_id' => $category->id,
            'status' => 'active',
            'price' => 1500,
            'min_price' => 1500,
            'max_price' => 1500,
            'total_stock' => 50,
        ]);
    }

    private function createClient(): Client
    {
        return Client::create([
            'name' => 'Tasnim Rahman',
            'phone' => '01700000000',
            'status' => 'active',
        ]);
    }

    private function createDeliveredOrder(Client $client, Product $product): array
    {
        $order = Order::create([
            'invoice_no' => 'INV-TEST-100',
            'client_id' => $client->id,
            'total_amount' => 1500,
            'grand_total' => 1500,
            'paid_amount' => 1500,
            'status' => OrderStatusEnum::DELIVERED,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'unit_price' => 1500,
            'quantity' => 1,
            'total' => 1500,
            'product_snapshot' => ['name' => $product->name],
        ]);

        return [$order, $orderItem];
    }

    public function test_can_fetch_product_reviews(): void
    {
        $product = $this->createProduct();
        $client1 = Client::create(['name' => 'Jane Doe', 'phone' => '01711111111']);
        $client2 = Client::create(['name' => 'John Smith', 'phone' => '01722222222']);

        ProductReview::create([
            'product_id' => $product->id,
            'client_id' => $client1->id,
            'rating' => 5,
            'title' => 'Amazing product',
            'content' => 'Loved using this essence.',
            'status' => 'approved',
        ]);

        ProductReview::create([
            'product_id' => $product->id,
            'client_id' => $client2->id,
            'rating' => 4,
            'title' => 'Good value',
            'content' => 'Works well.',
            'status' => 'approved',
        ]);

        $response = $this->getJson("/customer/product/{$product->id}/reviews");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('resources.summary.total_reviews', 2)
            ->assertJsonPath('resources.summary.average_rating', 4.5)
            ->assertJsonPath('resources.summary.breakdown.5', 1)
            ->assertJsonPath('resources.summary.breakdown.4', 1);
    }

    public function test_authenticated_client_can_submit_product_review_for_delivered_order_item(): void
    {
        $product = $this->createProduct();
        $client = $this->createClient();
        [$order, $orderItem] = $this->createDeliveredOrder($client, $product);

        Sanctum::actingAs($client, [], 'sanctum');

        $payload = [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'rating' => 5,
            'title' => 'Holy grail essence',
            'content' => 'This product changed my skin texture completely.',
        ];

        $response = $this->postJson("/customer/product/{$product->id}/reviews", $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'resources' => [
                    'name' => 'Tasnim Rahman',
                    'rating' => 5,
                    'title' => 'Holy grail essence',
                ],
            ]);

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'rating' => 5,
        ]);
    }

    public function test_unauthenticated_client_cannot_submit_review(): void
    {
        $product = $this->createProduct();
        $client = $this->createClient();
        [$order, $orderItem] = $this->createDeliveredOrder($client, $product);

        $payload = [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'rating' => 5,
            'content' => 'Great product!',
        ];

        $response = $this->postJson("/customer/product/{$product->id}/reviews", $payload);

        $response->assertStatus(401);
    }

    public function test_cannot_submit_review_for_non_delivered_order(): void
    {
        $product = $this->createProduct();
        $client = $this->createClient();

        $order = Order::create([
            'invoice_no' => 'INV-TEST-200',
            'client_id' => $client->id,
            'total_amount' => 1500,
            'grand_total' => 1500,
            'paid_amount' => 1500,
            'status' => OrderStatusEnum::PLACED,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'unit_price' => 1500,
            'quantity' => 1,
            'total' => 1500,
        ]);

        Sanctum::actingAs($client, [], 'sanctum');

        $payload = [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'rating' => 5,
            'content' => 'Reviewing early!',
        ];

        $response = $this->postJson("/customer/product/{$product->id}/reviews", $payload);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Reviews can only be submitted for delivered orders.');
    }

    public function test_cannot_submit_duplicate_review_for_same_order_item(): void
    {
        $product = $this->createProduct();
        $client = $this->createClient();
        [$order, $orderItem] = $this->createDeliveredOrder($client, $product);

        Sanctum::actingAs($client, [], 'sanctum');

        $payload = [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'rating' => 5,
            'content' => 'First review!',
        ];

        $this->postJson("/customer/product/{$product->id}/reviews", $payload)
            ->assertStatus(201);

        $secondResponse = $this->postJson("/customer/product/{$product->id}/reviews", $payload);

        $secondResponse->assertStatus(422)
            ->assertJsonPath('message', 'You have already submitted a review for this order item.');
    }

    public function test_can_vote_helpful_on_review(): void
    {
        $product = $this->createProduct();
        $client = $this->createClient();

        $review = ProductReview::create([
            'product_id' => $product->id,
            'client_id' => $client->id,
            'rating' => 5,
            'title' => 'Amazing product',
            'content' => 'Loved using this essence.',
            'helpful_count' => 3,
            'status' => 'approved',
        ]);

        $response = $this->postJson("/customer/reviews/{$review->id}/helpful");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'resources' => [
                    'id' => (string) $review->id,
                    'helpfulCount' => 4,
                ],
            ]);

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'helpful_count' => 4,
        ]);
    }
}
