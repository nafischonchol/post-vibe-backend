<?php

namespace App\Actions\Product;

use App\Models\Attribute;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class GetProductEditPayloadAction
{
    /**
     * Execute the retrieval and mapping of product edit data.
     */
    public function execute(Product $product)
    {
        // Eager load relations for performance
        $product->load([
            'category',
            'brand',
            'unit',
            'images',
            'variants.attributeValues',
            'specifications.attribute',
            'specifications.predefinedValues',
        ]);

        // Determine if product has variants by checking if any variant has attribute options
        $variants = $product->variants;
        $hasVariants = $variants->contains(fn ($v) => $v->attributeValues->isNotEmpty());

        // Construct response schema
        $payload = [
            'id' => $product->id,
            'name' => $product->name,
            'bangla_name' => $product->bangla_name,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'bangla_description' => $product->bangla_description,
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'unit_id' => $product->unit_id,
            'youtube_video_urls' => $product->youtube_video_urls ?? [],
            'authenticity_video_url' => $product->authenticity_video_url,
            'weight' => $product->weight,
            'status' => $product->status->value ?? $product->status,

            'thumbnail_relative' => $product->image,
            'thumbnail_url' => $product->image ? Storage::url($product->image) : null,

            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'meta_keywords' => $product->meta_keywords ?? [],
            'meta_image_relative' => $product->meta_image,
            'meta_image_url' => $product->meta_image ? Storage::url($product->meta_image) : null,

            'has_variants' => $hasVariants,
            'gallery_images' => [],
            'specifications' => [],
            'variant_attributes' => [],
            'variants' => [],
            'color_images' => [],
            'color_images_previews' => [],
        ];

        // Map gallery images (attribute_value is null and not the primary thumbnail)
        $payload['gallery_images'] = $product->images
            ->whereNull('attribute_value')
            ->where('is_main', false)
            ->map(function ($img) {
                return [
                    'id' => $img->id,
                    'image_relative' => $img->image_path,
                    'image_url' => Storage::url($img->image_path),
                ];
            })->values()->toArray();

        // Map color variant images
        $colorImages = [];
        $colorImagesPreviews = [];
        foreach ($product->images->whereNotNull('attribute_value') as $img) {
            $colorImages[$img->attribute_value] = $img->image_path;
            $colorImagesPreviews[$img->attribute_value] = Storage::url($img->image_path);
        }
        $payload['color_images'] = $colorImages;
        $payload['color_images_previews'] = $colorImagesPreviews;

        // Map specifications
        $payload['specifications'] = $product->specifications->map(function ($spec) {
            $attr = $spec->attribute;
            if (! $attr) {
                return null;
            }

            if ($attr->type === Attribute::TYPE['TEXT'] || $attr->type === Attribute::TYPE['RICH_TEXT']) {
                $value = $spec->custom_value;
            } elseif ($attr->type === Attribute::TYPE['SELECT']) {
                $value = $spec->predefinedValues->first()?->value;
            } else {
                $value = $spec->predefinedValues->pluck('value')->toArray();
            }

            return [
                'id' => $attr->id,
                'name' => $attr->name,
                'type' => $attr->type,
                'values' => $attr->values ?? [],
                'value' => $value,
            ];
        })->filter()->values()->toArray();

        // Map variants
        if ($variants->isNotEmpty()) {
            if ($hasVariants) {
                $attributeValuesGrouped = collect();
                foreach ($product->variants as $variant) {
                    foreach ($variant->attributeValues as $val) {
                        $attributeValuesGrouped->push($val);
                    }
                }

                $grouped = $attributeValuesGrouped->unique('id')->groupBy('attribute_id');

                $variantAttributes = [];
                foreach ($grouped as $attrId => $values) {
                    $attribute = Attribute::find($attrId);
                    if ($attribute) {
                        $variantAttributes[] = [
                            'id' => $attribute->id,
                            'name' => $attribute->name,
                            'type' => $attribute->type,
                            'values' => $attribute->values ?? [],
                            'selectedValues' => $values->pluck('value')->unique()->values()->toArray(),
                        ];
                    }
                }
                $payload['variant_attributes'] = $variantAttributes;
            }

            $payload['variants'] = $product->variants->map(function ($variant) use ($hasVariants, $payload) {
                $options = [];
                if ($hasVariants) {
                    $options = $variant->attributeValues->map(function ($val) {
                        return [
                            'attribute_id' => $val->attribute_id,
                            'value' => $val->value,
                        ];
                    })->toArray();
                }

                $title = '';
                if ($hasVariants) {
                    $orderedValues = [];
                    foreach ($payload['variant_attributes'] as $vAttr) {
                        foreach ($options as $opt) {
                            if ($opt['attribute_id'] == $vAttr['id']) {
                                $orderedValues[] = $opt['value'];
                            }
                        }
                    }
                    $title = implode(' / ', $orderedValues);
                }

                return [
                    'id' => $variant->id,
                    'title' => $title,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'discount_price' => $variant->discount_price,
                    'purchase_price' => $variant->purchase_price,
                    'barcode' => $variant->barcode,
                    'is_active' => (bool) ($variant->is_active ?? true),
                    'options' => $options,
                ];
            })->values()->toArray();
        }

        return responseSuccess($payload, 'Product edit payload retrieved successfully');
    }
}
