<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
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
        $totalStock = $variants->isNotEmpty() ? $variants->sum('stock') : 0;

        $price = null;
        if (! $hasVariants && $variants->isNotEmpty()) {
            $price = $variants->first()->price;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'bangla_name' => $this->bangla_name,
            'slug' => $this->slug,
            'category' => $this->relationLoaded('category') && $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null,
            'brand' => $this->relationLoaded('brand') && $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null,
            'thumbnail' => $this->image ? Storage::url($this->image) : null,
            'status' => $this->status->value ?? $this->status,
            'has_variants' => $hasVariants,
            'price' => $price,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'total_stock' => (int) $totalStock,
            'variants' => $this->relationLoaded('variants') ? $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => $v->price,
                'purchase_price' => $v->purchase_price,
                'stock' => $v->stock,
            ]) : [],
        ];
    }
}
