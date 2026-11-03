<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WishlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'guest_token' => $this->guest_token,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product' => [
                'id' => $this->product?->id,
                'name' => $this->product?->name,
                'slug' => $this->product?->slug,
                'selling_price' => $this->product?->selling_price,
                'primary_image_url' => $this->product?->images?->first()?->image_url ?? $this->product?->primary_image_url ?? null,
            ],
            'variant' => $this->variant ? [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'price' => $this->variant->selling_price ?? $this->variant->price ?? null,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
