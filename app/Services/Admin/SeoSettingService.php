<?php

namespace App\Services\Admin;

use App\Http\Resources\Seo404LogResource;
use App\Http\Resources\SeoSettingResource;
use App\Models\Seo404Log;
use App\Models\SeoSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SeoSettingService
{
    /**
     * Get public SEO settings for customer storefront with 20h caching.
     */
    public function getPublicSettings()
    {
        $cachedData = Cache::get('customer_seo_settings');

        if ($cachedData !== null) {
            return responseSuccess($cachedData);
        }

        $setting = SeoSetting::first();
        if (! $setting) {
            $setting = SeoSetting::create([
                'google_search_console_id' => null,
                'bing_webmaster_id' => null,
                'baidu_webmaster_id' => null,
                'yandex_webmaster_id' => null,
                'robots_txt' => "User-agent: *\nDisallow: /admin/",
                'sitemap_enabled' => false,
                'robots_meta_content' => [
                    'noindex' => false,
                    'nofollow' => false,
                    'noarchive' => false,
                    'nosnippet' => false,
                    'noimageindex' => false,
                ],
                'meta_pixel_id' => null,
                'meta_capi_token' => null,
                'meta_test_event_code' => null,
                'meta_api_version' => 'v20.0',
                'google_analytics_id' => null,
                'tiktok_pixel_id' => null,
                'tiktok_access_token' => null,
                'tiktok_test_event_code' => null,
                'tiktok_api_version' => 'v1.3',
            ]);
        }

        $resolved = SeoSettingResource::make($setting)->resolve();
        Cache::put('customer_seo_settings', $resolved, now()->addHours(20));

        return responseSuccess($resolved);
    }

    /**
     * Get the SEO settings.
     */
    public function getSettings()
    {
        $setting = SeoSetting::firstOrCreate(
            [],
            [
                'google_search_console_id' => null,
                'bing_webmaster_id' => null,
                'baidu_webmaster_id' => null,
                'yandex_webmaster_id' => null,
                'robots_txt' => "User-agent: *\nDisallow: /admin/",
                'sitemap_enabled' => false,
                'robots_meta_content' => [
                    'noindex' => false,
                    'nofollow' => false,
                    'noarchive' => false,
                    'nosnippet' => false,
                    'noimageindex' => false,
                ],
                'meta_pixel_id' => null,
                'meta_capi_token' => null,
                'meta_test_event_code' => null,
                'meta_api_version' => 'v20.0',
                'google_analytics_id' => null,
                'tiktok_pixel_id' => null,
                'tiktok_access_token' => null,
                'tiktok_test_event_code' => null,
                'tiktok_api_version' => 'v1.3',
            ]
        );

        return responseSuccess(SeoSettingResource::make($setting));
    }

    /**
     * Update or create the SEO settings.
     */
    public function updateOrCreate($request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'google_search_console_id' => ['nullable', 'string', 'max:255'],
                'bing_webmaster_id' => ['nullable', 'string', 'max:255'],
                'baidu_webmaster_id' => ['nullable', 'string', 'max:255'],
                'yandex_webmaster_id' => ['nullable', 'string', 'max:255'],
                'robots_txt' => ['nullable', 'string'],
                'sitemap_enabled' => ['required', 'boolean'],
                'robots_meta_content' => ['nullable', 'array'],
                'meta_pixel_id' => ['nullable', 'string', 'max:255'],
                'meta_capi_token' => ['nullable', 'string'],
                'meta_test_event_code' => ['nullable', 'string', 'max:255'],
                'meta_api_version' => ['nullable', 'string', 'max:50'],
                'google_analytics_id' => ['nullable', 'string', 'max:255'],
                'tiktok_pixel_id' => ['nullable', 'string', 'max:255'],
                'tiktok_access_token' => ['nullable', 'string'],
                'tiktok_test_event_code' => ['nullable', 'string', 'max:255'],
                'tiktok_api_version' => ['nullable', 'string', 'max:50'],
            ]);

            if ($validator->fails()) {
                return responseError($validator->errors()->first(), 422);
            }

            $setting = SeoSetting::first();
            $data = [
                'google_search_console_id' => $request->input('google_search_console_id'),
                'bing_webmaster_id' => $request->input('bing_webmaster_id'),
                'baidu_webmaster_id' => $request->input('baidu_webmaster_id'),
                'yandex_webmaster_id' => $request->input('yandex_webmaster_id'),
                'robots_txt' => $request->input('robots_txt'),
                'sitemap_enabled' => $request->boolean('sitemap_enabled'),
                'robots_meta_content' => $request->input('robots_meta_content'),
                'meta_pixel_id' => $request->input('meta_pixel_id'),
                'meta_capi_token' => $request->input('meta_capi_token'),
                'meta_test_event_code' => $request->input('meta_test_event_code'),
                'meta_api_version' => $request->input('meta_api_version') ?: 'v20.0',
                'google_analytics_id' => $request->input('google_analytics_id'),
                'tiktok_pixel_id' => $request->input('tiktok_pixel_id'),
                'tiktok_access_token' => $request->input('tiktok_access_token'),
                'tiktok_test_event_code' => $request->input('tiktok_test_event_code'),
                'tiktok_api_version' => $request->input('tiktok_api_version') ?: 'v1.3',
            ];

            if ($setting) {
                $setting->update($data);
            } else {
                $setting = SeoSetting::create($data);
            }

            Cache::forget('customer_seo_settings');

            return responseSuccess(
                SeoSettingResource::make($setting),
                'SEO settings saved successfully.'
            );
        } catch (\Exception $e) {
            return responseError('Failed to save SEO settings: '.$e->getMessage(), 500, $e);
        }
    }

    /**
     * Get 404 logs. Auto-populates mock data if empty.
     */
    public function get404Logs()
    {
        $logs = Seo404Log::orderBy('last_accessed_at', 'desc')
            ->get();

        if ($logs->isEmpty()) {
            // Seed a few mock entries
            $now = now();
            $mockData = [
                [
                    'path' => '/wp-admin/',
                    'referrer' => 'Direct',
                    'hits' => 18,
                    'last_accessed_at' => $now->copy()->subMinutes(12),
                ],
                [
                    'path' => '/shop/old-product-slug',
                    'referrer' => 'https://google.com/',
                    'hits' => 6,
                    'last_accessed_at' => $now->copy()->subHours(2),
                ],
                [
                    'path' => '/category/undefined',
                    'referrer' => 'https://facebook.com/',
                    'hits' => 4,
                    'last_accessed_at' => $now->copy()->subHours(5),
                ],
                [
                    'path' => '/assets/logo-old.png',
                    'referrer' => 'https://yourstore.com/about',
                    'hits' => 25,
                    'last_accessed_at' => $now->copy()->subDays(1),
                ],
            ];

            foreach ($mockData as $data) {
                Seo404Log::create($data);
            }

            $logs = Seo404Log::orderBy('last_accessed_at', 'desc')
                ->get();
        }

        return responseSuccess(Seo404LogResource::collection($logs));
    }

    /**
     * Clear all 404 logs.
     */
    public function clear404Logs()
    {
        try {
            Seo404Log::query()->delete();

            return responseSuccess([], '404 logs cleared successfully.');
        } catch (\Exception $e) {
            return responseError('Failed to clear 404 logs: '.$e->getMessage(), 500, $e);
        }
    }
}
