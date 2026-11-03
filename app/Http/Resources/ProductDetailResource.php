<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : collect();

        $hasVariants = $this->has_variants;

        $minPrice = $variants->isNotEmpty() ? $variants->min('price') : 0;
        $maxPrice = $variants->isNotEmpty() ? $variants->max('price') : 0;
        $minPurchasePrice = $variants->isNotEmpty() ? $variants->min('purchase_price') : 0;
        $maxPurchasePrice = $variants->isNotEmpty() ? $variants->max('purchase_price') : 0;
        $totalStock = $variants->isNotEmpty() ? $variants->sum('stock') : 0;

        $price = null;
        $discountPrice = null;
        $barcode = null;
        $sku = null;
        $purchasePrice = null;

        if (! $hasVariants && $variants->isNotEmpty()) {
            $firstVariant = $variants->first();
            $price = $firstVariant->price;
            $discountPrice = $firstVariant->discount_price;
            $barcode = $firstVariant->barcode;
            $sku = $firstVariant->sku;
            $purchasePrice = $firstVariant->purchase_price;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'bangla_name' => $this->bangla_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'bangla_description' => $this->bangla_description,
            'short_description' => $this->short_description,
            'category' => $this->relationLoaded('category') && $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null,
            'brand' => $this->relationLoaded('brand') && $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null,
            'unit' => $this->relationLoaded('unit') && $this->unit ? [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
            ] : null,
            'thumbnail' => $this->image ? Storage::url($this->image) : null,
            'authenticity_video_url' => $this->authenticity_video_url,
            'youtube_video_urls' => $this->youtube_video_urls ?? [],
            'status' => $this->status->value ?? $this->status,
            'has_variants' => $hasVariants,
            'price' => $price,
            'discount_price' => $discountPrice,
            'purchase_price' => $purchasePrice,
            'barcode' => $barcode,
            'sku' => $sku,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'min_purchase_price' => $minPurchasePrice,
            'max_purchase_price' => $maxPurchasePrice,
            'total_stock' => (int) $totalStock,
            'variants' => $variants->map(function ($variant) {
                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'price' => (float) $variant->price,
                    'discount_price' => $variant->discount_price ? (float) $variant->discount_price : null,
                    'purchase_price' => $variant->purchase_price ? (float) $variant->purchase_price : null,
                    'stock' => (int) $variant->stock,
                ];
            }),
        ];
    }
}
