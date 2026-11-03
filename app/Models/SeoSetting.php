<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SeoSetting extends Model
{
    use HasFactory;

    protected $table = 'seo_settings';

    protected $fillable = [
        'google_search_console_id',
        'bing_webmaster_id',
        'baidu_webmaster_id',
        'yandex_webmaster_id',
        'robots_txt',
        'sitemap_enabled',
        'robots_meta_content',
        'meta_pixel_id',
        'meta_capi_token',
        'meta_test_event_code',
        'meta_api_version',
        'google_analytics_id',
        'tiktok_pixel_id',
        'tiktok_access_token',
        'tiktok_test_event_code',
        'tiktok_api_version',
    ];

    protected $casts = [
        'sitemap_enabled' => 'boolean',
        'robots_meta_content' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget('customer_seo_settings');
        });

        static::deleted(function () {
            Cache::forget('customer_seo_settings');
        });
    }
}
