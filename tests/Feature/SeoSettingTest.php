<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeoSettingTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin()
    {
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $this->actingAs($adminUser, 'sanctum');

        return $adminUser;
    }

    public function test_can_retrieve_default_seo_settings()
    {
        $this->authenticateAdmin();

        $response = $this->getJson('/admin/seo-settings');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.google_search_console_id', null)
            ->assertJsonPath('resources.yandex_webmaster_id', null)
            ->assertJsonPath('resources.meta_pixel_id', null)
            ->assertJsonPath('resources.meta_capi_token', null)
            ->assertJsonPath('resources.meta_test_event_code', null)
            ->assertJsonPath('resources.meta_api_version', 'v20.0')
            ->assertJsonPath('resources.google_analytics_id', null)
            ->assertJsonPath('resources.tiktok_pixel_id', null)
            ->assertJsonPath('resources.tiktok_access_token', null)
            ->assertJsonPath('resources.tiktok_test_event_code', null)
            ->assertJsonPath('resources.tiktok_api_version', 'v1.3')
            ->assertJsonPath('resources.robots_txt', "User-agent: *\nDisallow: /admin/")
            ->assertJsonPath('resources.sitemap_enabled', false);
    }

    public function test_can_update_seo_settings()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/seo-settings', [
            'google_search_console_id' => 'google-key-123',
            'bing_webmaster_id' => 'bing-key-456',
            'baidu_webmaster_id' => 'baidu-key-789',
            'yandex_webmaster_id' => 'yandex-key-abc',
            'robots_txt' => 'Disallow: /temp/',
            'sitemap_enabled' => true,
            'robots_meta_content' => [
                'noindex' => true,
                'nofollow' => false,
                'noarchive' => false,
                'nosnippet' => false,
                'noimageindex' => false,
            ],
            'meta_pixel_id' => 'pixel-123',
            'meta_capi_token' => 'capi-token-456',
            'meta_test_event_code' => 'TEST_FB_123',
            'meta_api_version' => 'v20.0',
            'google_analytics_id' => 'ga-789',
            'tiktok_pixel_id' => 'tiktok-abc',
            'tiktok_access_token' => 'tiktok-token-123',
            'tiktok_test_event_code' => 'TEST999',
            'tiktok_api_version' => 'v1.3',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('resources.google_search_console_id', 'google-key-123')
            ->assertJsonPath('resources.yandex_webmaster_id', 'yandex-key-abc')
            ->assertJsonPath('resources.meta_pixel_id', 'pixel-123')
            ->assertJsonPath('resources.meta_capi_token', 'capi-token-456')
            ->assertJsonPath('resources.meta_test_event_code', 'TEST_FB_123')
            ->assertJsonPath('resources.meta_api_version', 'v20.0')
            ->assertJsonPath('resources.google_analytics_id', 'ga-789')
            ->assertJsonPath('resources.tiktok_pixel_id', 'tiktok-abc')
            ->assertJsonPath('resources.tiktok_access_token', 'tiktok-token-123')
            ->assertJsonPath('resources.tiktok_test_event_code', 'TEST999')
            ->assertJsonPath('resources.tiktok_api_version', 'v1.3')
            ->assertJsonPath('resources.sitemap_enabled', true)
            ->assertJsonPath('resources.robots_meta_content.noindex', true);

        $this->assertDatabaseHas('seo_settings', [
            'google_search_console_id' => 'google-key-123',
            'yandex_webmaster_id' => 'yandex-key-abc',
            'meta_pixel_id' => 'pixel-123',
            'meta_capi_token' => 'capi-token-456',
            'meta_test_event_code' => 'TEST_FB_123',
            'meta_api_version' => 'v20.0',
            'google_analytics_id' => 'ga-789',
            'tiktok_pixel_id' => 'tiktok-abc',
            'tiktok_access_token' => 'tiktok-token-123',
            'tiktok_test_event_code' => 'TEST999',
            'tiktok_api_version' => 'v1.3',
            'sitemap_enabled' => true,
        ]);
    }

    public function test_can_retrieve_404_logs_and_auto_populates()
    {
        $this->authenticateAdmin();

        $response = $this->getJson('/admin/seo-settings/404-logs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertGreaterThan(0, count($response->json('resources')));
        $this->assertDatabaseHas('seo_404_logs', [
            'path' => '/wp-admin/',
        ]);
    }

    public function test_can_clear_404_logs()
    {
        $adminUser = $this->authenticateAdmin();

        // Ensure logs are seeded/created first
        $this->getJson('/admin/seo-settings/404-logs');
        $this->assertDatabaseHas('seo_404_logs', ['path' => '/wp-admin/']);

        $response = $this->deleteJson('/admin/seo-settings/404-logs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('seo_404_logs', ['path' => '/wp-admin/']);
    }

    public function test_can_retrieve_public_seo_settings_and_caches()
    {
        Cache::forget('customer_seo_settings');

        $response = $this->getJson('/customer/seo-settings');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue(Cache::has('customer_seo_settings'));
    }

    public function test_updating_seo_settings_invalidates_cache()
    {
        $this->authenticateAdmin();

        // Populate cache
        $this->getJson('/customer/seo-settings');
        $this->assertTrue(Cache::has('customer_seo_settings'));

        // Update settings
        $response = $this->postJson('/admin/seo-settings', [
            'google_search_console_id' => 'new-google-key',
            'sitemap_enabled' => true,
        ]);
        $response->assertStatus(200);

        // Assert customer cache was forgotten
        $this->assertFalse(Cache::has('customer_seo_settings'));

        // Next fetch should retrieve updated value
        $publicRes = $this->getJson('/customer/seo-settings');
        $publicRes->assertStatus(200)
            ->assertJsonPath('resources.google_search_console_id', 'new-google-key');
    }
}
