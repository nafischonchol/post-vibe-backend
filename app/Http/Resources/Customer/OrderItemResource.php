<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $imagePath = $this->variant?->image ?? $product?->image ?? $product?->images->first()?->image_path ?? null;
        $imageUrl = $imagePath ? (str_starts_with($imagePath, 'http') ? $imagePath : Storage::url($imagePath)) : null;

        $review = $this->relationLoaded('review') ? $this->review : $this->review()->first();

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->product_snapshot['name'] ?? ($product?->name ?? ''),
            'sku' => $this->product_snapshot['sku'] ?? ($this->variant?->sku ?? ''),
            'variant_title' => $this->product_snapshot['variant_title'] ?? '',
            'unit_price' => (float) $this->unit_price,
            'quantity' => (int) $this->quantity,
            'total' => (float) $this->total,
            'is_gift' => (bool) $this->is_gift,
            'image' => $imageUrl,
            'product_slug' => $product ? ($product->slug.'-'.$product->id) : null,
            'has_review' => $review !== null,
            'review' => $review ? [
                'id' => $review->id,
                'rating' => (int) $review->rating,
                'title' => $review->title,
                'content' => $review->content,
            ] : null,
        ];
    }
}
