<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->combo_id && $this->combo) {
            $combo = $this->combo;
            $stock = (int) $combo->stock;
            $requestedQty = (int) $this->quantity;
            $status = 1;
            $reason = null;

            if (! $combo->is_active) {
                $status = 0;
                $reason = 'This combo offer is currently unavailable or inactive.';
            } elseif ($stock < $requestedQty) {
                $status = 0;
                $reason = $stock <= 0 ? 'This combo offer is out of stock.' : "Only {$stock} combo packages available.";
            }

            return [
                'id' => $this->id,
                'is_combo' => true,
                'combo_id' => $this->combo_id,
                'product_id' => null,
                'product_variant_id' => null,
                'name' => $combo->name,
                'bangla_name' => $combo->bangla_name,
                'price' => (float) $combo->combo_price,
                'regular_price' => (float) $combo->regular_price,
                'save_amount' => (float) $combo->save_amount,
                'image' => $combo->image ? Storage::url($combo->image) : null,
                'quantity' => $requestedQty,
                'brand' => 'Combo Offer',
                'sku' => "COMBO-{$combo->id}",
                'slug_url' => $combo->slug_url,
                'status' => $status,
                'reason' => $reason,
                'stock' => $stock,
            ];
        }

        $product = $this->product;
        $variant = $this->variant;

        $rawPrice = $variant?->discount_price ?? $variant?->price ?? 0;
        $imagePath = $variant?->image ?? $product?->image ?? null;
        $imageUrl = $imagePath ? Storage::url($imagePath) : null;

        $isProductActive = $product && (
            ($product->status instanceof \App\Enums\ProductStatusEnum && $product->status === \App\Enums\ProductStatusEnum::ACTIVE)
            || $product->status === 'active'
            || (is_object($product->status) && isset($product->status->value) && $product->status->value === 'active')
        );
        $isVariantActive = $variant && (bool) $variant->is_active;
        $stock = $variant ? (int) $variant->stock : 0;
        $requestedQty = (int) $this->quantity;

        $status = 1;
        $reason = null;

        if (! $product || ! $isProductActive || ! $variant || ! $isVariantActive) {
            $status = 0;
            $reason = 'Product is currently unavailable or inactive.';
        } elseif ($stock < $requestedQty) {
            $status = 0;
            if ($stock <= 0) {
                $reason = "Insufficient stock for product SKU: {$variant->sku}";
            } else {
                $reason = "Insufficient stock for product SKU: {$variant->sku} (Only {$stock} available)";
            }
        }

        return [
            'id' => $this->id,
            'is_combo' => false,
            'combo_id' => null,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'name' => $product?->name ?? '',
            'price' => (float) $rawPrice,
            'image' => $imageUrl,
            'quantity' => $requestedQty,
            'brand' => $product?->brand?->name ?? null,
            'sku' => $variant?->sku ?? null,
            'slug_url' => $product?->slug_url ?? null,
            'status' => $status,
            'reason' => $reason,
            'stock' => $stock,
        ];
    }
}
