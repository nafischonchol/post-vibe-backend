<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeoSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'google_search_console_id' => $this->google_search_console_id,
            'bing_webmaster_id' => $this->bing_webmaster_id,
            'baidu_webmaster_id' => $this->baidu_webmaster_id,
            'yandex_webmaster_id' => $this->yandex_webmaster_id,
            'robots_txt' => $this->robots_txt,
            'sitemap_enabled' => (bool) $this->sitemap_enabled,
            'meta_pixel_id' => $this->meta_pixel_id,
            'meta_capi_token' => $this->meta_capi_token,
            'meta_test_event_code' => $this->meta_test_event_code,
            'meta_api_version' => $this->meta_api_version ?? 'v20.0',
            'google_analytics_id' => $this->google_analytics_id,
            'tiktok_pixel_id' => $this->tiktok_pixel_id,
            'tiktok_access_token' => $this->tiktok_access_token,
            'tiktok_test_event_code' => $this->tiktok_test_event_code,
            'tiktok_api_version' => $this->tiktok_api_version ?? 'v1.3',
            'robots_meta_content' => $this->robots_meta_content ?? [
                'noindex' => false,
                'nofollow' => false,
                'noarchive' => false,
                'nosnippet' => false,
                'noimageindex' => false,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
