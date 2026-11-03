<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(string $name = 'Test Product'): Product
    {
        $category = Category::create([
            'name' => 'Category '.Str::random(5),
            'slug' => 'cat-'.Str::random(5),
            'is_active' => true,
        ]);

        return Product::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'category_id' => $category->id,
            'status' => 'active',
        ]);
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
        ]);
    }

    public function test_guest_visitor_session_creates_a_unique_ulid_token()
    {
        $response = $this->getJson('/customer/visitor/session');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $guestToken = $response->json('resources.guest_token');
        $this->assertNotEmpty($guestToken);
    }

    public function test_guest_user_can_add_product_to_wishlist()
    {
        $product = $this->createProduct('Wishlist Item 1');
        $guestToken = (string) Str::ulid();

        $response = $this->withHeader('X-Visitor-Id', $guestToken)
            ->postJson('/customer/wishlist/toggle', [
                'product_id' => $product->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.attached', true);

        $this->assertDatabaseHas('wishlists', [
            'guest_token' => $guestToken,
            'product_id' => $product->id,
            'client_id' => null,
        ]);
    }

    public function test_guest_user_toggling_product_removes_it_from_wishlist()
    {
        $product = $this->createProduct('Wishlist Item 2');
        $guestToken = (string) Str::ulid();

        Wishlist::create([
            'guest_token' => $guestToken,
            'product_id' => $product->id,
        ]);

        $response = $this->withHeader('X-Visitor-Id', $guestToken)
            ->postJson('/customer/wishlist/toggle', [
                'product_id' => $product->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.attached', false);

        $this->assertDatabaseMissing('wishlists', [
            'guest_token' => $guestToken,
            'product_id' => $product->id,
        ]);
    }

    public function test_logging_in_merges_guest_wishlist_items_to_client_wishlist_without_duplicates()
    {
        $client = $this->createClient();
        $product1 = $this->createProduct('Product A');
        $product2 = $this->createProduct('Product B');
        $guestToken = (string) Str::ulid();

        // Guest has product1 & product2 in wishlist
        Wishlist::create(['guest_token' => $guestToken, 'product_id' => $product1->id]);
        Wishlist::create(['guest_token' => $guestToken, 'product_id' => $product2->id]);

        // Client already had product1 in wishlist
        Wishlist::create(['client_id' => $client->id, 'product_id' => $product1->id]);

        // Merge request
        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/customer/me/wishlist/merge', [
                'guest_token' => $guestToken,
            ]);

        $response->assertStatus(200);

        // Verify guest items are cleaned up and client has both items uniquely
        $this->assertDatabaseMissing('wishlists', ['guest_token' => $guestToken]);
        $this->assertDatabaseHas('wishlists', ['client_id' => $client->id, 'product_id' => $product1->id]);
        $this->assertDatabaseHas('wishlists', ['client_id' => $client->id, 'product_id' => $product2->id]);

        $this->assertEquals(2, Wishlist::where('client_id', $client->id)->count());
    }
}
