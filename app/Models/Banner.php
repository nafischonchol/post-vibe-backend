<?php

namespace App\Models;

use App\Enums\BannerTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'short_description',
        'redirect_url',
        'banner_image',
        'mobile_banner_image',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'type' => BannerTypeEnum::class,
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            static::clearBannerCache();
        });

        static::deleted(function () {
            static::clearBannerCache();
        });
    }

    public static function clearBannerCache(): void
    {
        foreach (BannerTypeEnum::values() as $type) {
            Cache::forget("banners_{$type}");
        }
    }
}
