<?php

namespace App\Services\AiChat\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ProductStockCheckTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'check_product_stock',
                'description' => 'Check real-time live inventory stock and availability directly from the database for a specific product. Call this whenever a customer asks if a product is available or in stock ("stock ache ki?", "available ache?", "stock koto?").',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => [
                            'type' => 'string',
                            'description' => 'Name or title of the product to check (e.g. "COSRX Snail Mucin", "Beauty of Joseon Sunscreen").',
                        ],
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'Optional product ID if known from previous conversation context.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("ProductStockCheckTool executed", ['args' => $args]);

        $productId = isset($args['product_id']) && is_numeric($args['product_id']) ? (int) $args['product_id'] : null;
        $productName = trim($args['product_name'] ?? '');

        $product = null;

        // 1. Direct lookup by ID if provided
        if ($productId) {
            $product = Product::with(['variants' => fn($q) => $q->where('is_active', true), 'brand', 'category'])->find($productId);
        }

        // 2. Lookup by Name if not found or ID was not provided
        if (!$product && !empty($productName)) {
            $product = Product::with(['variants' => fn($q) => $q->where('is_active', true), 'brand', 'category'])
                ->where('status', 'active')
                ->where(function ($q) use ($productName) {
                    $q->where('name', 'like', "%{$productName}%")
                      ->orWhere('bangla_name', 'like', "%{$productName}%")
                      ->orWhere('slug', 'like', "%{$productName}%");
                })
                ->first();

            // Word token fallback
            if (!$product) {
                $words = array_filter(preg_split('/\s+/u', $productName), fn($w) => mb_strlen($w) >= 3);
                if (!empty($words)) {
                    $searchTerm = implode(' ', array_slice($words, 0, 3));
                    $product = Product::with(['variants' => fn($q) => $q->where('is_active', true), 'brand', 'category'])
                        ->where('status', 'active')
                        ->where('name', 'like', "%{$searchTerm}%")
                        ->first();
                }
            }
        }

        if (!$product) {
            return [
                'found' => false,
                'message' => "প্রোডাক্টটি খুঁজে পাওয়া যায়নি। দয়া করে সঠিক পণ্যের নাম অথবা কী-ওয়ার্ড উল্লেখ করুন।",
                'guidance' => "Politely explain to the customer that the product could not be found with this specific name. Call search_products with a broader keyword to suggest matching alternatives.",
            ];
        }

        $totalStock = (int) $product->variants->sum('stock');
        $isInStock = $totalStock > 0;

        $variantDetails = [];
        $minPrice = null;
        foreach ($product->variants as $v) {
            $effectivePrice = (float) ($v->discount_price ?: $v->price);
            if ($minPrice === null || $effectivePrice < $minPrice) {
                $minPrice = $effectivePrice;
            }
            $variantDetails[] = [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => (float) $v->price,
                'discount_price' => $v->discount_price ? (float) $v->discount_price : null,
                'effective_price' => $effectivePrice,
                'stock' => (int) $v->stock,
                'is_in_stock' => (int) $v->stock > 0,
            ];
        }

        return [
            'found' => true,
            'product_id' => $product->id,
            'name' => $product->name,
            'bangla_name' => $product->bangla_name ?? '',
            'slug' => $product->slug,
            'slug_url' => $product->slug_url,
            'image' => $product->image ? Product::getImageUrl($product->image) : null,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'in_stock' => $isInStock,
            'total_stock' => $totalStock,
            'starting_price' => $minPrice,
            'variants_count' => count($variantDetails),
            'variants' => $variantDetails,
            'guidance' => $isInStock
                ? "The product IS IN STOCK (Total quantity available: {$totalStock} units). Inform the customer cheerfully, share the price, and encourage them to order or add to cart."
                : "The product is currently OUT OF STOCK. Politely inform the customer that it is currently sold out, and immediately offer to recommend similar products from the same brand or category.",
        ];
    }
}
