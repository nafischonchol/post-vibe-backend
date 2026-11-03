<?php

namespace App\Services\Admin;

use App\Enums\StockMovementType;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

class StockMovementService
{
    public static function record(
        ProductVariant $variant,
        StockMovementType $type,
        int $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?string $createdById = null,
    ): StockMovement {
        $before = $variant->stock;
        $after = $before + $quantity;

        $movement = StockMovement::create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'type' => $type->value,
            'quantity' => $quantity,
            'stock_before' => $before,
            'stock_after' => $after,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->id,
            'reason' => $reason,
            'created_by_id' => $createdById,
        ]);

        $variant->increment('stock', $quantity);

        return $movement;
    }
}
