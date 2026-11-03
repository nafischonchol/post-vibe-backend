<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ComboResource extends JsonResource
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
            'combo_price' => (float) $this->combo_price,
            'regular_price' => (float) $this->regular_price,
            'save_amount' => (float) $this->save_amount,
            'save_percentage' => (int) $this->save_percentage,
            'stock' => (int) $this->stock,
            'image' => $this->image ? Storage::url($this->image) : null,
            'video_url' => $this->video_url,
            'items_count' => $this->relationLoaded('items') ? $this->items->count() : (int) $this->items()->count(),
            'items_preview' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    $p = $item->product;
                    $v = $item->variant;
                    return [
                        'product_id' => $item->product_id,
                        'name' => $p?->name,
                        'brand' => $p?->brand?->name,
                        'quantity' => (int) $item->quantity,
                        'image' => $v?->image ? Storage::url($v->image) : ($p?->image ? Storage::url($p->image) : null),
                    ];
                });
            }),
        ];
    }
}
