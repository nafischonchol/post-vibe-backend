<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ComboDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bangla_name' => $this->bangla_name,
            'slug' => $this->slug,
            'slug_url' => $this->slug_url,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'bangla_description' => $this->bangla_description,
            'combo_price' => (float) $this->combo_price,
            'regular_price' => (float) $this->regular_price,
            'save_amount' => (float) $this->save_amount,
            'save_percentage' => (int) $this->save_percentage,
            'stock' => (int) $this->stock,
            'image' => $this->image ? Storage::url($this->image) : null,
            'video_url' => $this->video_url,
            'items' => $this->items->map(function ($item) {
                $variant = $item->variant;
                $product = $item->product;

                $attributes = $variant && $variant->relationLoaded('attributeValues')
                    ? $variant->attributeValues->map(fn ($av) => ($av->attribute?->name ? "{$av->attribute->name}: " : '').$av->value)->implode(', ')
                    : '';

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $product?->name ?? '',
                    'product_slug_url' => $product?->slug_url ?? (string) $item->product_id,
                    'brand_name' => $product?->brand?->name ?? '',
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $variant?->sku ?? '',
                    'variant_title' => $attributes ?: ($variant?->sku ?? 'Standard'),
                    'unit_price' => (float) ($variant?->discount_price ?? $variant?->price ?? 0),
                    'quantity' => (int) $item->quantity,
                    'line_total' => (float) (($variant?->discount_price ?? $variant?->price ?? 0) * $item->quantity),
                    'stock' => (int) ($variant?->stock ?? 0),
                    'image' => $variant?->image ? Storage::url($variant->image) : ($product?->image ? Storage::url($product->image) : null),
                ];
            }),
        ];
    }
}
