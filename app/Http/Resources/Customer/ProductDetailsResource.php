<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : collect();

        $hasVariants = $variants->count() > 1;
        if ($variants->count() === 1) {
            $sku = $variants->first()->sku ?? '';
            if ($sku && ! str_contains($sku, '-default-')) {
                $hasVariants = true;
            }
        }

        $minPrice = $variants->isNotEmpty() ? $variants->min('price') : 0;
        $maxPrice = $variants->isNotEmpty() ? $variants->max('price') : 0;
        $minDiscountPrice = $variants->isNotEmpty() ? $variants->min('discount_price') : 0;
        $maxDiscountPrice = $variants->isNotEmpty() ? $variants->max('discount_price') : 0;

        $price = null;
        $discountPrice = null;
        if (! $hasVariants && $variants->isNotEmpty()) {
            $price = $variants->first()->price;
            $discountPrice = $variants->first()->discount_price;
        }

        $mappedVariants = $variants->map(function ($variant) {
            $attributes = $variant->relationLoaded('attributeValues') ? $variant->attributeValues->mapWithKeys(function ($av) {
                return [$av->attribute->name => $av->value];
            })->toArray() : [];

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'discount_price' => $variant->discount_price,
                'stock' => $variant->stock,
                'attributes' => $attributes,
            ];
        });

        $aggregatedAttributes = [];
        if ($variants->isNotEmpty() && $variants->first()->relationLoaded('attributeValues')) {
            foreach ($variants as $variant) {
                foreach ($variant->attributeValues as $av) {
                    if ($av->relationLoaded('attribute')) {
                        $attrName = $av->attribute->name;
                        if (! isset($aggregatedAttributes[$attrName])) {
                            $aggregatedAttributes[$attrName] = [];
                        }
                        if (! in_array($av->value, $aggregatedAttributes[$attrName])) {
                            $aggregatedAttributes[$attrName][] = $av->value;
                        }
                    }
                }
            }
        }

        $formattedAttributes = [];
        foreach ($aggregatedAttributes as $name => $values) {
            $formattedAttributes[] = [
                'name' => $name,
                'values' => $values,
            ];
        }

        $keyIngredientValue = null;
        $specificationsFormatted = $this->whenLoaded('specifications', function () use (&$keyIngredientValue) {
            return $this->specifications->map(function ($spec) use (&$keyIngredientValue) {
                $attrName = $spec->attribute ? $spec->attribute->name : null;
                $val = $spec->value;
                if ($attrName && strcasecmp(trim($attrName), 'Key Ingredient') === 0) {
                    $keyIngredientValue = $val;
                }

                return [
                    'id' => $spec->id,
                    'attribute_id' => $spec->attribute_id,
                    'attribute_name' => $attrName,
                    'value' => $val,
                ];
            });
        });

        return [
            'id' => $this->id,
            'name' => $this->name,
            'bangla_name' => $this->bangla_name,
            'slug' => $this->slug,
            'slug_url' => $this->slug_url,
            'description' => $this->description,
            'bangla_description' => $this->bangla_description,
            'short_description' => $this->short_description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'ingredients' => $keyIngredientValue,
            'category' => $this->whenLoaded('category', function () {
                $breadcrumbs = [];
                if ($this->category) {
                    $ancestors = $this->category->relationLoaded('ancestors') ? $this->category->ancestors : collect();
                    foreach ($ancestors as $ancestor) {
                        $breadcrumbs[] = [
                            'id' => $ancestor->id,
                            'name' => $ancestor->name,
                            'slug' => $ancestor->slug,
                            'slug_url' => $ancestor->slug_url,
                        ];
                    }
                    $breadcrumbs[] = [
                        'id' => $this->category->id,
                        'name' => $this->category->name,
                        'slug' => $this->category->slug,
                        'slug_url' => $this->category->slug_url,
                    ];
                }

                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                    'slug_url' => $this->category->slug_url,
                    'breadcrumbs' => $breadcrumbs,
                ];
            }),
            'brand' => $this->whenLoaded('brand', function () {
                return [
                    'id' => $this->brand->id,
                    'name' => $this->brand->name,
                    'authenticity_video_url' => $this->brand->authenticity_video_url,
                ];
            }),
            'authenticity_video_url' => $this->authenticity_video_url
                ?? ($this->relationLoaded('brand') && $this->brand ? $this->brand->authenticity_video_url : null),
            'youtube_video_urls' => $this->youtube_video_urls ?? [],
            'landing_headline' => $this->landing_headline,
            'landing_subtitle' => $this->landing_subtitle,
            'landing_badge' => $this->landing_badge,
            'landing_video_url' => $this->landing_video_url,
            'landing_benefits' => $this->landing_benefits,
            'landing_features' => $this->landing_features,
            'landing_faqs' => $this->landing_faqs,
            'landing_config' => $this->relationLoaded('landingConfig') ? $this->landingConfig : null,
            'tier_offers' => $this->relationLoaded('tierOffers') ? $this->tierOffers->map(function ($to) {
                return [
                    'id' => $to->id,
                    'quantity' => (int) $to->quantity,
                    'title' => $to->title,
                    'subtitle' => $to->subtitle,
                    'discount_amount' => (float) $to->discount_amount,
                    'discount_percentage' => (float) $to->discount_percentage,
                    'is_free_shipping' => (bool) $to->is_free_shipping,
                    'gift_text' => $to->gift_text,
                    'badge_text' => $to->badge_text,
                    'is_popular' => (bool) $to->is_popular,
                    'sort_order' => (int) $to->sort_order,
                ];
            }) : [],
            'landing_videos' => $this->relationLoaded('landingVideos') ? $this->landingVideos->map(function ($lv) {
                $fp = $lv->featuredProduct;
                $price = 0;
                if ($fp) {
                    $firstVar = $fp->variants->first();
                    $price = $firstVar ? ($firstVar->discount_price > 0 ? (float) $firstVar->discount_price : (float) $firstVar->price) : 0;
                }

                return [
                    'id' => $lv->id,
                    'video_url' => $lv->video_url,
                    'video_id' => $lv->video_id,
                    'thumbnail_url' => $lv->thumbnail_url,
                    'embed_url' => $lv->embed_url,
                    'title' => $lv->title,
                    'badge_text' => $lv->badge_text,
                    'featured_product_id' => $lv->featured_product_id,
                    'featured_product' => $fp ? [
                        'id' => $fp->id,
                        'name' => $fp->name,
                        'slug' => $fp->slug_url ?: $fp->slug,
                        'brand_name' => $fp->brand?->name,
                        'price' => $price,
                        'thumbnail' => ! empty($fp->image) ? Storage::url($fp->image) : null,
                    ] : null,
                    'sort_order' => (int) $lv->sort_order,
                ];
            }) : [],
            'thumbnail' => ! empty($this->image) ? Storage::url($this->image) : null,
            'blur_image' => $this->blur_image,
            'images' => $this->whenLoaded('images', function () {
                return $this->images
                    ->filter(fn ($img) => ! empty($img->image_path))
                    ->map(fn ($img) => Storage::url($img->image_path))
                    ->values();
            }),
            'specifications' => $specificationsFormatted,
            'has_variants' => $hasVariants,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'min_discount_price' => $minDiscountPrice,
            'max_discount_price' => $maxDiscountPrice,
            'price' => $price ?? $minPrice,
            'discount_price' => $discountPrice ?? $minDiscountPrice,
            'status' => $this->status->value ?? $this->status,
            'rating' => $this->relationLoaded('reviews')
                ? ($this->reviews->where('status', 'approved')->count() > 0 ? round((float) $this->reviews->where('status', 'approved')->avg('rating'), 1) : 0)
                : ((int) $this->reviews()->where('status', 'approved')->count() > 0 ? round((float) $this->reviews()->where('status', 'approved')->avg('rating'), 1) : 0),
            'reviews_count' => $this->relationLoaded('reviews')
                ? (int) $this->reviews->where('status', 'approved')->count()
                : (int) $this->reviews()->where('status', 'approved')->count(),
            'questions_count' => $this->relationLoaded('questions')
                ? (int) $this->questions->where('status', 'approved')->count()
                : (int) $this->questions()->where('status', 'approved')->count(),
            'variants' => $mappedVariants,
            'attributes' => $formattedAttributes,
        ];
    }
}
