<?php

namespace App\Services\AiChat\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class CompareProductsTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'compare_products',
                'description' => 'Compare 2 to 3 products side-by-side in terms of specifications, price, live stock, suitability for skin types, and key differences. Always call this whenever a customer asks to compare products, asks for differences between products, or asks which of two options to choose.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of product names or keywords to compare (e.g. ["The Ordinary Niacinamide", "COSRX Snail Mucin"]).',
                        ],
                        'product_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Optional list of specific product IDs to compare if already known.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("CompareProductsTool executed", $args);

        $productIds = !empty($args['product_ids']) && is_array($args['product_ids'])
            ? array_filter(array_map('intval', $args['product_ids']))
            : [];

        $productNames = !empty($args['product_names']) && is_array($args['product_names'])
            ? array_filter(array_map('trim', $args['product_names']))
            : [];

        $resolvedProducts = collect();

        if (!empty($productIds)) {
            $byIds = Product::whereIn('id', $productIds)
                ->with(['category', 'brand', 'variants', 'specifications'])
                ->get();
            $resolvedProducts = $resolvedProducts->merge($byIds);
        }

        foreach ($productNames as $name) {
            if ($resolvedProducts->contains(fn($p) => mb_stripos($p->name, $name) !== false)) {
                continue;
            }

            $found = Product::where('status', 'active')
                ->where(function ($q) use ($name) {
                    $q->where('name', 'like', "%{$name}%")
                      ->orWhere('bangla_name', 'like', "%{$name}%");
                })
                ->with(['category', 'brand', 'variants', 'specifications'])
                ->first();

            if (!$found) {
                $words = array_filter(explode(' ', $name), fn($w) => mb_strlen($w) >= 3);
                if (!empty($words)) {
                    $found = Product::where('status', 'active')
                        ->where(function ($q) use ($words) {
                            foreach ($words as $w) {
                                $q->orWhere('name', 'like', "%{$w}%");
                            }
                        })
                        ->with(['category', 'brand', 'variants', 'specifications'])
                        ->first();
                }
            }

            if ($found && !$resolvedProducts->contains('id', $found->id)) {
                $resolvedProducts->push($found);
            }
        }

        if ($resolvedProducts->isEmpty()) {
            return [
                'success' => false,
                'message' => 'তুলনা করার জন্য উল্লিখিত কোনো প্রোডাক্ট খুঁজে পাওয়া যায়নি।',
            ];
        }

        $comparisonList = [];
        foreach ($resolvedProducts as $product) {
            $firstVariant = $product->variants->first();
            $price = $firstVariant ? (float) ($firstVariant->discount_price ?: $firstVariant->price) : 0;
            $regularPrice = $firstVariant ? (float) $firstVariant->price : 0;
            $stock = (int) $product->variants->sum('stock');

            $specs = $product->specifications->map(function ($s) {
                return ($s->name ?? 'Feature') . ': ' . ($s->value ?? '');
            })->values()->toArray();

            $comparisonList[] = [
                'id' => $product->id,
                'name' => $product->name,
                'bangla_name' => $product->bangla_name ?? '',
                'slug' => $product->slug,
                'slug_url' => $product->slug_url,
                'brand' => $product->brand?->name ?? 'Standard',
                'category' => $product->category?->name ?? 'Skincare',
                'price' => $price,
                'regular_price' => $regularPrice,
                'stock' => $stock,
                'in_stock' => $stock > 0,
                'image' => $product->image ? Product::getImageUrl($product->image) : null,
                'specifications' => $specs,
                'short_description' => strip_tags($product->short_description ?: substr($product->description ?? '', 0, 160)),
            ];
        }

        return [
            'success' => true,
            'count' => count($comparisonList),
            'products' => $comparisonList,
            'guidance' => 'Present a clear comparison table or structured bullets highlight: 1) Key differences in function, 2) Price & stock, 3) Who should choose which product based on skin concerns.',
        ];
    }
}
