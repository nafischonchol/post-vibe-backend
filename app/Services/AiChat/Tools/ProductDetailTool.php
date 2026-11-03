<?php

namespace App\Services\AiChat\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ProductDetailTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_product_details',
                'description' => 'Get full details, specifications, variants, and stock for a specific product by its ID or slug.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'description' => 'The ID of the product (preferred if known).',
                        ],
                        'product_name' => [
                            'type' => 'string',
                            'description' => 'The name or keyword of the product if ID/slug is not known.',
                        ],
                        'slug' => [
                            'type' => 'string',
                            'description' => 'The slug of the product',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("ProductDetailTool args", $args);
        $productId = isset($args['product_id']) && is_numeric($args['product_id']) ? (int) $args['product_id'] : null;
        $slug = trim($args['slug'] ?? '');
        $productName = trim($args['product_name'] ?? '');

        if (!$productId && empty($slug) && empty($productName)) {
            return ['error' => 'Please provide product_id, product_name, or slug'];
        }

        $query = Product::query()
            ->with([
                'variants.attributeValues.attribute',
                'category',
                'brand',
                'images',
                'specifications',
            ]);

        $product = null;

        if ($productId) {
            $product = $query->find($productId);
        }

        if (!$product && !empty($slug)) {
            $product = (clone $query)->where('slug', $slug)->first();
        }

        // Fuzzy/token search by product_name if not found yet
        if (!$product && !empty($productName)) {
            $product = (clone $query)->where('status', 'active')
                ->where(function ($q) use ($productName) {
                    $q->where('name', 'like', "%{$productName}%")
                      ->orWhere('bangla_name', 'like', "%{$productName}%")
                      ->orWhere('slug', 'like', "%{$productName}%");
                })
                ->first();

            if (!$product) {
                $cleanTerms = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $productName);
                $words = array_values(array_filter(explode(' ', $cleanTerms), fn($w) => mb_strlen($w) >= 2));

                if (!empty($words)) {
                    $escapedRaw = addslashes($productName);
                    $scoreParts = [];
                    $scoreParts[] = "CASE WHEN products.name = '{$escapedRaw}' THEN 100 WHEN products.name LIKE '%{$escapedRaw}%' THEN 50 ELSE 0 END";

                    foreach ($words as $word) {
                        $escapedWord = addslashes($word);
                        $scoreParts[] = "CASE WHEN products.name LIKE '%{$escapedWord}%' THEN 10 ELSE 0 END";
                    }

                    $brandConditions = implode(' OR ', array_map(fn($w) => "brands.name LIKE '%" . addslashes($w) . "%'", $words));
                    $scoreParts[] = "(SELECT CASE WHEN COUNT(*) > 0 THEN 25 ELSE 0 END FROM brands WHERE brands.id = products.brand_id AND ({$brandConditions}))";

                    $relevanceScoreSql = implode(' + ', $scoreParts);
                    $product = (clone $query)->where('status', 'active')
                        ->orderByRaw('(' . $relevanceScoreSql . ') DESC')
                        ->first();
                }
            }
        }

        if (!$product) {
            return ['error' => 'Product not found.'];
        }

        $variants = $product->variants->map(function ($variant) {
            $attrs = $variant->attributeValues->map(function ($val) {
                return ($val->attribute?->name ? $val->attribute->name . ': ' : '') . $val->value;
            })->join(', ');

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $attrs ?: 'Standard',
                'price' => (float) $variant->price,
                'discount_price' => $variant->discount_price ? (float) $variant->discount_price : null,
                'effective_price' => (float) ($variant->discount_price ?: $variant->price),
                'stock' => (int) $variant->stock,
                'in_stock' => $variant->stock > 0,
            ];
        });

        $specs = $product->specifications->map(function ($spec) {
            return [
                'name' => $spec->name ?? 'Feature',
                'value' => $spec->value ?? '',
            ];
        });

        $gallery = $product->images->map(fn($img) => Product::getImageUrl($img->image))->filter()->values()->toArray();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'bangla_name' => $product->bangla_name ?? '',
            'slug' => $product->slug,
            'slug_url' => $product->slug_url,
            'description' => strip_tags($product->description ?? ''),
            'bangla_description' => strip_tags($product->bangla_description ?? ''),
            'category' => $product->category?->name,
            'brand' => $product->brand?->name,
            'image' => $product->image ? Product::getImageUrl($product->image) : null,
            'gallery_images' => $gallery,
            'total_stock' => $product->variants->sum('stock'),
            'variants' => $variants,
            'specifications' => $specs,
        ];
    }
}
