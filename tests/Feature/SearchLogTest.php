<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchLogTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin(): Admin
    {
        $admin = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin@moima.com',
            'password' => bcrypt('secret123'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function createSampleProduct(string $name = 'Silk Saree'): Product
    {
        $category = Category::create([
            'name' => 'Clothing',
            'slug' => 'clothing',
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'name' => 'Aarong',
            'slug' => 'aarong',
            'is_active' => true,
        ]);

        return Product::create([
            'name' => $name,
            'bangla_name' => 'সিল্ক শাড়ি',
            'slug' => Str::slug($name),
            'slug_url' => Str::slug($name).'-1',
            'sku' => 'SKU-'.rand(100, 999),
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'status' => 'active',
        ]);
    }

    public function test_search_suggestions_returns_results_fast_without_polluting_logs()
    {
        $product = $this->createSampleProduct('Silk Saree');
        $visitorId = (string) Str::ulid();

        $response = $this->withHeaders([
            'X-Visitor-Id' => $visitorId,
        ])->getJson('/customer/products/search-suggestions?q=Silk');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Autocomplete typing alone does not create search logs
        $this->assertDatabaseMissing('search_logs', [
            'query' => 'Silk',
            'visitor_id' => $visitorId,
        ]);
    }

    public function test_filter_products_with_search_text_logs_with_visitor_id()
    {
        $this->createSampleProduct('Jamdani Saree');
        $visitorId = (string) Str::ulid();

        $response = $this->withHeaders([
            'X-Visitor-Id' => $visitorId,
        ])->getJson('/customer/products/filter?search_text=Jamdani');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('search_logs', [
            'query' => 'Jamdani',
            'normalized_query' => 'jamdani',
            'results_count' => 1,
            'visitor_id' => $visitorId,
            'source' => 'catalog_page',
        ]);
    }

    public function test_zero_result_search_is_logged_properly()
    {
        $visitorId = (string) Str::ulid();

        $response = $this->withHeaders([
            'X-Visitor-Id' => $visitorId,
        ])->getJson('/customer/products/filter?search_text=NonExistentKeywordXYZ');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('search_logs', [
            'query' => 'NonExistentKeywordXYZ',
            'normalized_query' => 'nonexistentkeywordxyz',
            'results_count' => 0,
            'visitor_id' => $visitorId,
            'source' => 'catalog_page',
        ]);
    }

    public function test_customer_can_track_click_on_search_result()
    {
        $product = $this->createSampleProduct('Cotton Saree');
        $visitorId = (string) Str::ulid();

        // Simulate search log entry
        $log = SearchLog::create([
            'query' => 'Cotton',
            'normalized_query' => 'cotton',
            'results_count' => 1,
            'source' => 'autocomplete',
            'visitor_id' => $visitorId,
        ]);

        // Customer clicks product
        $response = $this->withHeaders([
            'X-Visitor-Id' => $visitorId,
        ])->postJson('/customer/search-logs/click', [
            'product_id' => $product->id,
            'search_log_id' => $log->id,
            'query' => 'Cotton',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.tracked', true);

        $this->assertDatabaseHas('search_logs', [
            'id' => $log->id,
            'clicked_product_id' => $product->id,
        ]);
    }

    public function test_admin_can_view_summary_and_audit_reports()
    {
        $admin = $this->authenticateAdmin();
        $product = $this->createSampleProduct('Silk Saree');

        // Create sample search logs
        SearchLog::create([
            'query' => 'Silk Saree',
            'normalized_query' => 'silk saree',
            'results_count' => 5,
            'visitor_id' => 'v-123',
            'clicked_product_id' => $product->id,
            'clicked_at' => now(),
        ]);

        SearchLog::create([
            'query' => 'shari',
            'normalized_query' => 'shari',
            'results_count' => 0,
            'visitor_id' => 'v-456',
        ]);

        // 1. Summary API
        $summaryRes = $this->getJson('/admin/search-logs/summary?days=7');
        $summaryRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.total_searches', 2)
            ->assertJsonPath('resources.zero_result_searches', 1)
            ->assertJsonPath('resources.total_clicks', 1);

        // 2. Trending API
        $trendingRes = $this->getJson('/admin/search-logs/trending?days=7');
        $trendingRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'resources');

        // 3. Search but Not Found API
        $notFoundRes = $this->getJson('/admin/search-logs/not-found?days=7');
        $notFoundRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'resources')
            ->assertJsonPath('resources.0.normalized_query', 'shari');

        // 4. Raw Logs Stream API
        $logsRes = $this->getJson('/admin/search-logs?only_zero_results=1');
        $logsRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'resources');
    }

    public function test_admin_can_purge_old_search_logs()
    {
        $this->authenticateAdmin();

        // Create old log (70 days ago)
        $oldLog = SearchLog::create([
            'query' => 'Old Query',
            'normalized_query' => 'old query',
            'results_count' => 1,
            'visitor_id' => 'v-old',
        ]);
        SearchLog::where('id', $oldLog->id)->update(['created_at' => now()->subDays(70)]);

        // Create new log (1 day ago)
        $newLog = SearchLog::create([
            'query' => 'Recent Query',
            'normalized_query' => 'recent query',
            'results_count' => 1,
            'visitor_id' => 'v-new',
        ]);

        $res = $this->deleteJson('/admin/search-logs/clear?days=60');
        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('search_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('search_logs', ['id' => $newLog->id]);
    }
}
