<?php

namespace App\Services\Customer;

use App\Enums\BannerTypeEnum;
use App\Http\Resources\Customer\BannerResource;
use App\Models\Banner;
use Illuminate\Support\Facades\Cache;

class BannerService
{
    public function getByType(string $type)
    {
        if (! in_array($type, BannerTypeEnum::values())) {
            return responseError('Invalid banner type', 400);
        }

        $cacheKey = "banners_{$type}";

        if (Cache::has($cacheKey)) {
            $banners = Cache::get($cacheKey);
        } else {
            $banners = Banner::where('type', $type)
                ->where('is_active', true)
                ->latest('id')
                ->get();

            Cache::put($cacheKey, $banners, now()->addHours(10));
        }

        return responseSuccess(BannerResource::collection($banners));
    }
}
