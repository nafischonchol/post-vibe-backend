<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'authenticity_video_url',
        'is_active',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'meta_image',
        'seo_bottom_content',
        'seo_bottom_content_bn',
        'faqs',
        'faqs_bn',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta_keyword' => 'array',
        'faqs' => 'array',
        'faqs_bn' => 'array',
    ];

    public function slugUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->slug.'-'.$this->id,
        );
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public static function clearBrandCache(Brand $brand): void
    {
        Cache::forget('brands_index_'.md5(''));
        Cache::forget('popular_brands_8');

        // Invalidate product details cache for all products under this brand
        Product::where('brand_id', $brand->id)->pluck('id')->each(function ($productId) {
            Cache::forget("product_details_{$productId}");
        });

        for ($i = 1; $i <= 10; $i++) {
            Cache::forget("popular_products_p{$i}_l20");
            Cache::forget("new_arrival_products_p{$i}_l20");
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($brand) {
            if (empty($brand->slug)) {
                $brand->slug = Str::slug($brand->name);
            }
        });

        static::updating(function ($brand) {
            if (empty($brand->slug)) {
                $brand->slug = Str::slug($brand->name);
            }
        });

        static::saved(function ($brand) {
            static::clearBrandCache($brand);
        });

        static::deleted(function ($brand) {
            static::clearBrandCache($brand);
        });
    }
}
