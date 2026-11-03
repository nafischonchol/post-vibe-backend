<?php

namespace App\Actions\Order;

use App\Enums\OrderStatusEnum;
use App\Enums\StockMovementType;
use App\Http\Requests\Admin\Order\UpdateOrderItemsRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Traits\HandlesOrderCreation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateOrderItemsAction
{
    use HandlesOrderCreation;

    public function execute(Order $order, UpdateOrderItemsRequest $request): Order
    {
        $allowedStatuses = [
            OrderStatusEnum::PLACED,
            OrderStatusEnum::CONFIRMED,
            OrderStatusEnum::PACKAGING,
            OrderStatusEnum::READY_TO_DELIVER,
        ];

        if (! in_array($order->status, $allowedStatuses, true)) {
            abort(422, "Cannot edit items for order in '{$order->status->value}' status.");
        }

        $items = $request->input('items', []);
        if (empty($items)) {
            abort(422, 'An order must contain at least one item.');
        }

        $userId = Auth::id();

        return DB::transaction(function () use ($order, $request, $items, $userId, $allowedStatuses) {
            /** @var Order $order */
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, $allowedStatuses, true)) {
                abort(422, "Cannot edit items for order in '{$order->status->value}' status.");
            }

            $existingItems = $order->items()->get()->keyBy('id');
            $payloadExistingItemIds = collect($items)
                ->pluck('order_item_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            $changes = [];

            // 1. Process REMOVED items
            foreach ($existingItems as $existingId => $existingItem) {
                if (! in_array($existingId, $payloadExistingItemIds, true)) {
                    $variant = ProductVariant::where('id', $existingItem->product_variant_id)->lockForUpdate()->first();
                    if ($variant) {
                        $stockBefore = $variant->stock;
                        $qty = $existingItem->quantity;
                        $variant->increment('stock', $qty);

                        StockMovement::create([
                            'product_id' => $existingItem->product_id,
                            'product_variant_id' => $existingItem->product_variant_id,
                            'type' => StockMovementType::ORDER_EDIT_RESTOCK,
                            'quantity' => $qty,
                            'stock_before' => $stockBefore,
                            'stock_after' => $stockBefore + $qty,
                            'reference_type' => OrderItem::class,
                            'reference_id' => $existingItem->id,
                            'reason' => "Order {$order->invoice_no} item removed via admin edit",
                            'created_by_id' => $userId,
                        ]);
                    }

                    $name = $existingItem->product_snapshot['name'] ?? 'Item';
                    $varTitle = $existingItem->product_snapshot['variant_title'] ?? '';
                    $changes[] = "Removed {$name}" . ($varTitle ? " ({$varTitle})" : '') . " x{$existingItem->quantity}";
                    $existingItem->delete();
                }
            }

            // 2. Process ADDED or MODIFIED items
            foreach ($items as $itemData) {
                $orderItemId = isset($itemData['order_item_id']) && $itemData['order_item_id'] ? (int) $itemData['order_item_id'] : null;

                if ($orderItemId && $existingItems->has($orderItemId)) {
                    $existingItem = $existingItems->get($orderItemId);
                    $newQty = (int) $itemData['quantity'];
                    $oldQty = (int) $existingItem->quantity;
                    $unitPrice = isset($itemData['unit_price']) && $itemData['unit_price'] !== null
                        ? (float) $itemData['unit_price']
                        : (float) $existingItem->unit_price;

                    $targetVariantId = ! empty($itemData['product_variant_id']) ? (int) $itemData['product_variant_id'] : (int) $existingItem->product_variant_id;

                    if ($targetVariantId !== (int) $existingItem->product_variant_id) {
                        // Replaced variant
                        $oldVariant = ProductVariant::where('id', $existingItem->product_variant_id)->lockForUpdate()->first();
                        if ($oldVariant) {
                            $oldStockBefore = $oldVariant->stock;
                            $oldVariant->increment('stock', $oldQty);
                            StockMovement::create([
                                'product_id' => $existingItem->product_id,
                                'product_variant_id' => $existingItem->product_variant_id,
                                'type' => StockMovementType::ORDER_EDIT_RESTOCK,
                                'quantity' => $oldQty,
                                'stock_before' => $oldStockBefore,
                                'stock_after' => $oldStockBefore + $oldQty,
                                'reference_type' => OrderItem::class,
                                'reference_id' => $existingItem->id,
                                'reason' => "Order {$order->invoice_no} item replaced via admin edit",
                                'created_by_id' => $userId,
                            ]);
                        }

                        $newVariant = ProductVariant::with(['product', 'attributeValues'])
                            ->where('id', $itemData['product_variant_id'])
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($newVariant->stock < $newQty) {
                            throw new \RuntimeException("Insufficient stock for variant SKU: {$newVariant->sku}. Available: {$newVariant->stock}, Needed: {$newQty}");
                        }

                        $newStockBefore = $newVariant->stock;
                        $newVariant->decrement('stock', $newQty);
                        $variantTitle = $this->getVariantTitle($newVariant);

                        $existingItem->update([
                            'product_id' => $newVariant->product_id,
                            'product_variant_id' => $newVariant->id,
                            'product_snapshot' => [
                                'name' => $newVariant->product->name ?? '',
                                'sku' => $newVariant->sku,
                                'variant_title' => $variantTitle,
                            ],
                            'unit_price' => $unitPrice,
                            'quantity' => $newQty,
                            'total' => round($unitPrice * $newQty, 2),
                        ]);

                        StockMovement::create([
                            'product_id' => $newVariant->product_id,
                            'product_variant_id' => $newVariant->id,
                            'type' => StockMovementType::SALE,
                            'quantity' => $newQty,
                            'stock_before' => $newStockBefore,
                            'stock_after' => $newStockBefore - $newQty,
                            'reference_type' => OrderItem::class,
                            'reference_id' => $existingItem->id,
                            'reason' => "Order {$order->invoice_no} replacement item added via admin edit",
                            'created_by_id' => $userId,
                        ]);

                        $changes[] = "Replaced item with {$newVariant->product->name} ({$variantTitle}) x{$newQty}";
                    } else {
                        // Same variant
                        $variant = ProductVariant::where('id', $existingItem->product_variant_id)->lockForUpdate()->firstOrFail();

                        if ($newQty > $oldQty) {
                            $diff = $newQty - $oldQty;
                            if ($variant->stock < $diff) {
                                throw new \RuntimeException("Insufficient stock for variant SKU: {$variant->sku}. Available: {$variant->stock}, Needed: {$diff}");
                            }
                            $stockBefore = $variant->stock;
                            $variant->decrement('stock', $diff);

                            StockMovement::create([
                                'product_id' => $variant->product_id,
                                'product_variant_id' => $variant->id,
                                'type' => StockMovementType::SALE,
                                'quantity' => $diff,
                                'stock_before' => $stockBefore,
                                'stock_after' => $stockBefore - $diff,
                                'reference_type' => OrderItem::class,
                                'reference_id' => $existingItem->id,
                                'reason' => "Order {$order->invoice_no} item quantity increased via admin edit (+{$diff})",
                                'created_by_id' => $userId,
                            ]);

                            $existingItem->update([
                                'unit_price' => $unitPrice,
                                'quantity' => $newQty,
                                'total' => round($unitPrice * $newQty, 2),
                            ]);

                            $name = $existingItem->product_snapshot['name'] ?? 'Item';
                            $changes[] = "Increased {$name} qty from {$oldQty} to {$newQty}";
                        } elseif ($newQty < $oldQty) {
                            $diff = $oldQty - $newQty;
                            $stockBefore = $variant->stock;
                            $variant->increment('stock', $diff);

                            StockMovement::create([
                                'product_id' => $variant->product_id,
                                'product_variant_id' => $variant->id,
                                'type' => StockMovementType::ORDER_EDIT_RESTOCK,
                                'quantity' => $diff,
                                'stock_before' => $stockBefore,
                                'stock_after' => $stockBefore + $diff,
                                'reference_type' => OrderItem::class,
                                'reference_id' => $existingItem->id,
                                'reason' => "Order {$order->invoice_no} item quantity decreased via admin edit (-{$diff})",
                                'created_by_id' => $userId,
                            ]);

                            $existingItem->update([
                                'unit_price' => $unitPrice,
                                'quantity' => $newQty,
                                'total' => round($unitPrice * $newQty, 2),
                            ]);

                            $name = $existingItem->product_snapshot['name'] ?? 'Item';
                            $changes[] = "Decreased {$name} qty from {$oldQty} to {$newQty}";
                        } else {
                            if (abs((float) $existingItem->unit_price - $unitPrice) > 0.001) {
                                $existingItem->update([
                                    'unit_price' => $unitPrice,
                                    'total' => round($unitPrice * $newQty, 2),
                                ]);
                                $name = $existingItem->product_snapshot['name'] ?? 'Item';
                                $changes[] = "Updated price for {$name} to ৳{$unitPrice}";
                            }
                        }
                    }
                } else {
                    // New item
                    $variant = ProductVariant::with(['product', 'attributeValues'])
                        ->where('id', $itemData['product_variant_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $qty = (int) $itemData['quantity'];
                    if ($variant->stock < $qty) {
                        throw new \RuntimeException("Insufficient stock for variant SKU: {$variant->sku}. Available: {$variant->stock}, Needed: {$qty}");
                    }

                    $unitPrice = isset($itemData['unit_price']) && $itemData['unit_price'] !== null
                        ? (float) $itemData['unit_price']
                        : (float) $variant->selling_price;

                    $stockBefore = $variant->stock;
                    $variant->decrement('stock', $qty);

                    $variantTitle = $this->getVariantTitle($variant);

                    $newOrderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'product_snapshot' => [
                            'name' => $variant->product->name ?? '',
                            'sku' => $variant->sku,
                            'variant_title' => $variantTitle,
                        ],
                        'unit_price' => $unitPrice,
                        'quantity' => $qty,
                        'total' => round($unitPrice * $qty, 2),
                    ]);

                    StockMovement::create([
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'type' => StockMovementType::SALE,
                        'quantity' => $qty,
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockBefore - $qty,
                        'reference_type' => OrderItem::class,
                        'reference_id' => $newOrderItem->id,
                        'reason' => "Order {$order->invoice_no} item added via admin edit",
                        'created_by_id' => $userId,
                    ]);

                    $changes[] = "Added {$variant->product->name} ({$variantTitle}) x{$qty}";
                }
            }

            // 3. Recalculate Totals
            $freshItems = $order->items()->get();
            $totalAmount = (float) $freshItems->sum('total');
            $discountAmount = min((float) $order->discount_amount, $totalAmount);

            $taxRate = 0;
            $oldTaxable = (float) $order->total_amount - (float) $order->discount_amount;
            if ($oldTaxable > 0 && (float) $order->tax_amount > 0) {
                $taxRate = (float) $order->tax_amount / $oldTaxable;
            }
            $taxAmount = round(($totalAmount - $discountAmount) * $taxRate, 2);
            $deliveryCharge = $order->delivery_charge !== null ? (float) $order->delivery_charge : 0;
            $grandTotal = max(0, $totalAmount - $discountAmount + $taxAmount + $deliveryCharge);

            $oldGrandTotal = (float) $order->grand_total;
            $order->update([
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
            ]);

            // 4. Adjust excess payment allocations if needed
            $totalAllocated = (float) $order->allocations()->sum('allocated_amount');
            if ($totalAllocated > $grandTotal) {
                $excess = $totalAllocated - $grandTotal;
                foreach ($order->allocations()->orderByDesc('id')->get() as $allocation) {
                    if ($excess <= 0) {
                        break;
                    }
                    if ($allocation->allocated_amount <= $excess) {
                        $excess -= (float) $allocation->allocated_amount;
                        $allocation->delete();
                    } else {
                        $allocation->decrement('allocated_amount', $excess);
                        $excess = 0;
                    }
                }
            }

            // 5. Status history audit note
            $admin = Auth::user();
            $adminName = $admin?->name ?? 'Admin';
            $note = $request->input('note');
            $changesStr = ! empty($changes) ? implode('; ', $changes) : 'Items modified';
            $historyNote = "Items edited by {$adminName}: {$changesStr}. Grand total: ৳{$oldGrandTotal} → ৳{$grandTotal}.";
            if ($note) {
                $historyNote .= " Reason: {$note}.";
            }
            if ($order->parcel_booking_status === 'completed') {
                $oldCod = max(0, $oldGrandTotal - (float) $order->paid_amount);
                $newCod = max(0, $grandTotal - (float) $order->paid_amount);
                $historyNote .= " [Courier Alert: Parcel COD was ৳{$oldCod}, now ৳{$newCod}. Re-booking or courier update required.]";
            }

            $order->statusHistories()->create([
                'status' => $order->status,
                'note' => $historyNote,
                'changed_by_id' => $userId,
            ]);

            return $order;
        });
    }
}
