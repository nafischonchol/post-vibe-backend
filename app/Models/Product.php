<?php

namespace App\Models;

use App\Enums\ProductStatusEnum;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    /**
     * Resolves the proper public/CDN URL for a product image.
     */
    public static function getImageUrl(?string $image): ?string
    {
        if (empty($image)) {
            return null;
        }

        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            $host = parse_url($image, PHP_URL_HOST);
            $appHost = parse_url(config('app.url'), PHP_URL_HOST);
            if (in_array($host, ['127.0.0.1', 'localhost', $appHost])) {
                $path = ltrim(parse_url($image, PHP_URL_PATH) ?? '', '/');
                if (str_starts_with($path, 'storage/')) {
                    $path = substr($path, 8);
                }
                return Storage::url($path);
            }
            return $image;
        }

        return Storage::url($image);
    }

    protected $fillable = [
        'name',
        'bangla_name',
        'slug',
        'short_description',
        'description',
        'bangla_description',
        'category_id',
        'brand_id',
        'unit_id',
        'youtube_video_urls',
        'authenticity_video_url',
        'weight',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'meta_image',
        'image',
        'blur_image',
        'landing_headline',
        'landing_subtitle',
        'landing_badge',
        'landing_video_url',
        'landing_benefits',
        'landing_features',
        'landing_faqs',
        'status',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'meta_keywords' => 'array',
        'youtube_video_urls' => 'array',
        'landing_benefits' => 'array',
        'landing_features' => 'array',
        'landing_faqs' => 'array',
        'weight' => 'double',
        'status' => ProductStatusEnum::class,
    ];

    public function slugUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->slug.'-'.$this->id,
        );
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_main')->orderBy('id', 'asc');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function specifications()
    {
        return $this->hasMany(ProductSpecification::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class, 'product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class, 'product_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class, 'product_id');
    }

    public function landingConfig()
    {
        return $this->hasOne(ProductLandingConfig::class);
    }

    public function tierOffers()
    {
        return $this->hasMany(LandingTierOffer::class)->orderBy('sort_order', 'asc')->orderBy('quantity', 'asc');
    }

    public function landingVideos()
    {
        return $this->hasMany(LandingPageVideo::class)->orderBy('sort_order', 'asc');
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by_id');
    }

    public function updater()
    {
        return $this->belongsTo(Admin::class, 'updated_by_id');
    }

    public function getHasVariantsAttribute(): bool
    {
        $variants = $this->variants;
        if ($variants->count() > 1) {
            return true;
        }
        if ($variants->isEmpty()) {
            return false;
        }

        $variant = $variants->first();
        if ($variant->relationLoaded('attributeValues')) {
            return $variant->attributeValues->isNotEmpty();
        }

        return $variant->attributeValues()->exists();
    }

    protected static function booted(): void
    {
        static::saved(function (Product $product) {
            static::clearProductCache($product->id);
        });

        static::deleted(function (Product $product) {
            static::clearProductCache($product->id);
        });
    }

    public static function clearProductCache(int $productId): void
    {
        Cache::forget("product_details_{$productId}");

        for ($i = 1; $i <= 10; $i++) {
            Cache::forget("popular_products_p{$i}_l20");
            Cache::forget("new_arrival_products_p{$i}_l20");
        }
    }
}
