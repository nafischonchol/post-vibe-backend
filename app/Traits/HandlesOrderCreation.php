<?php

namespace App\Traits;

use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\StockMovement;

trait HandlesOrderCreation
{
    /**
     * Generate standard sequential invoice number with DB lock: INV{DDMMYYYY}-{NN}.
     */
    public function generateInvoiceNo(): string
    {
        $today = now()->format('dmY');
        $prefix = 'INV'.$today.'-';

        $latestOrder = Order::where('invoice_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        if ($latestOrder && ! empty($latestOrder->invoice_no)) {
            $parts = explode('-', $latestOrder->invoice_no);
            $lastSeq = (int) end($parts);
            $nextSeq = $lastSeq + 1;
        } else {
            $nextSeq = 1;
        }

        return $prefix.str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Generate variant title display string from attribute values or fallback to Default.
     */
    public function getVariantTitle(ProductVariant $variant): string
    {
        if ($variant->relationLoaded('attributeValues') ? $variant->attributeValues->isNotEmpty() : $variant->attributeValues()->exists()) {
            return $variant->attributeValues->pluck('value')->join(' / ');
        }

        return 'Default';
    }

    /**
     * Create order items, decrement product variant stock, and record stock movements.
     */
    public function createOrderItemsAndDeductStock(
        Order $order,
        array $variantData,
        ?int $userId = null,
        string $reasonPrefix = 'Sale order'
    ): void {
        foreach ($variantData as $vd) {
            $variant = $vd['variant'];
            $qty = (int) $vd['quantity'];
            $stockBefore = $variant->stock;

            $variantTitle = $this->getVariantTitle($variant);

            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'product_snapshot' => [
                    'name' => $variant->product->name ?? '',
                    'sku' => $variant->sku,
                    'variant_title' => $variantTitle,
                ],
                'unit_price' => $vd['unit_price'],
                'quantity' => $qty,
                'total' => $vd['line_total'],
            ]);

            $variant->decrement('stock', $qty);

            StockMovement::create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'type' => StockMovementType::SALE,
                'quantity' => $qty,
                'stock_before' => $stockBefore,
                'stock_after' => $stockBefore - $qty,
                'reference_type' => OrderItem::class,
                'reference_id' => $orderItem->id,
                'reason' => "{$reasonPrefix} {$order->invoice_no}",
                'created_by_id' => $userId,
            ]);
        }
    }
}
