<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

class Category extends Model
{
    use HasFactory;
    use HasRecursiveRelationships;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'icon',
        'is_active',
        'is_header_menu',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'meta_image',
        'seo_bottom_content',
        'seo_bottom_content_bn',
        'faqs',
        'faqs_bn',
        'landing_headline',
        'landing_subtitle',
        'landing_video_url',
        'landing_badge',
        'landing_benefits',
        'landing_steps',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_header_menu' => 'boolean',
        'meta_keyword' => 'array',
        'faqs' => 'array',
        'faqs_bn' => 'array',
        'landing_benefits' => 'array',
        'landing_steps' => 'array',
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

    public function landingConfig()
    {
        return $this->hasOne(CategoryLandingConfig::class);
    }

    public function landingVideos()
    {
        return $this->hasMany(LandingPageVideo::class)->orderBy('sort_order', 'asc');
    }

    public static function clearCategoryCache(Category $category): void
    {
        Cache::forget('popular_categories');
        Cache::forget('header_menu_categories');

        Product::where('category_id', $category->id)->pluck('id')->each(function ($productId) {
            Cache::forget("product_details_{$productId}");
        });
    }

    protected static function booted(): void
    {
        static::saved(function ($category) {
            static::clearCategoryCache($category);
        });

        static::deleted(function ($category) {
            static::clearCategoryCache($category);
        });
    }
}
