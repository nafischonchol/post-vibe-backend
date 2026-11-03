<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateProductPricesFromPurchaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, array{product_variant_id: int, cost_price: float|int, selling_price?: float|int|null}> $items
     */
    public function __construct(
        public array $items
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $touchedProductIds = [];

        foreach ($this->items as $item) {
            $variantId = $item['product_variant_id'] ?? null;
            if (! $variantId) {
                continue;
            }

            $variant = ProductVariant::find($variantId);
            if (! $variant) {
                continue;
            }

            $updates = [];
            if (isset($item['cost_price']) && is_numeric($item['cost_price'])) {
                $updates['purchase_price'] = (float) $item['cost_price'];
            }

            if (isset($item['selling_price']) && is_numeric($item['selling_price']) && (float) $item['selling_price'] > 0) {
                $updates['price'] = (float) $item['selling_price'];
            }

            if (! empty($updates)) {
                $variant->update($updates);
            }

            if ($variant->product_id) {
                $touchedProductIds[] = $variant->product_id;
            }
        }

        foreach (array_unique($touchedProductIds) as $productId) {
            Product::clearProductCache($productId);
        }
    }
}
