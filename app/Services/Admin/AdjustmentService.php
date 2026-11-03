<?php

namespace App\Services\Admin;

use App\Enums\StockMovementType;
use App\Models\Adjustment;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdjustmentService
{
    public function storeAdjustment(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $variant = ProductVariant::findOrFail($data['product_variant_id']);
            $quantity = $data['type'] === 'addition' ? (int) $data['quantity'] : -(int) $data['quantity'];

            $purchasePrice = $data['purchase_price'] ?? null;
            $unitPrice = $data['unit_price'] ?? null;

            $variantUpdateData = [];
            if ($purchasePrice !== null) {
                $variantUpdateData['purchase_price'] = $purchasePrice;
            }
            if ($unitPrice !== null) {
                $variantUpdateData['price'] = $unitPrice;
            }

            if (! empty($variantUpdateData)) {
                $variant->update($variantUpdateData);
            }

            $adjustment = Adjustment::create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'type' => $data['type'],
                'quantity' => $data['quantity'],
                'unit_price' => $unitPrice,
                'purchase_price' => $purchasePrice,
                'stock_before' => $variant->stock,
                'stock_after' => $variant->stock + $quantity,
                'reason' => $data['reason'] ?? null,
                'created_by_id' => Auth::id(),
            ]);

            StockMovementService::record(
                variant: $variant,
                type: StockMovementType::ADJUSTMENT,
                quantity: $quantity,
                reference: $adjustment,
                reason: $data['reason'] ?? null,
                createdById: Auth::id(),
            );

            return [
                'adjustment' => $adjustment,
                'variant' => $variant->fresh(),
            ];
        });
    }

    /**
     * Fetch paginated list of stock adjustments.
     */
    public function getPaginatedAdjustments(int $perPage = 20)
    {
        return Adjustment::with(['product:id,name', 'variant:id,sku,price', 'creator:id,name'])
            ->latest()
            ->paginate($perPage);
    }
}
