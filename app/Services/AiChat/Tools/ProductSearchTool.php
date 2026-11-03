<?php

namespace App\Services\AiChat\Tools;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;

class ProductSearchTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_products',
                'description' => 'Search products in Mohima (authentic Korean beauty & luxury skincare store). Can search by keyword/problem/ingredient, category, price range, or retrieve top/popular/best-selling products. Always call this whenever a customer asks for recommendations, products, or expresses a skin concern.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search keyword, product name, or skin concern/problem (e.g. "cleanser", "serum", "niacinamide", "dry skin moisturizer", "sunscreen", "acne"). Leave empty if requesting general top/popular store products.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Optional category name (e.g. "Cleansers", "Moisturizers", "Sun Care", "Serums & Ampoules", "Toners").',
                        ],
                        'sort_by' => [
                            'type' => 'string',
                            'enum' => ['popular', 'best_selling', 'price_low_to_high', 'price_high_to_low', 'relevance', 'newest'],
                            'description' => 'Sorting criteria. Use "popular" or "best_selling" to recommend top-selling and curated products. Use "price_low_to_high" when user wants affordable/budget items. Default is "popular" if query is general, or "relevance".',
                        ],
                        'min_price' => [
                            'type' => 'number',
                            'description' => 'Minimum price in BDT (৳)',
                        ],
                        'max_price' => [
                            'type' => 'number',
                            'description' => 'Maximum price in BDT (৳)',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Number of products to return (default 3 to 5, max 10)',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("ProductSearchTool executed", $args);
        $rawQuery = trim($args['query'] ?? '');
        $category = trim($args['category'] ?? '');
        $sortBy = $args['sort_by'] ?? ($rawQuery === '' ? 'popular' : 'relevance');
        $minPrice = isset($args['min_price']) && is_numeric($args['min_price']) ? (float) $args['min_price'] : null;
        $maxPrice = isset($args['max_price']) && is_numeric($args['max_price']) ? (float) $args['max_price'] : null;
        $limit = min(max((int) ($args['limit'] ?? 5), 1), 10);

        // Normalize common synonym keywords (e.g. facewash -> cleanser)
        $searchQuery = $rawQuery;
        if (preg_match('/face\s*wash|ফেসওয়াশ|মুখ ধোয়ার/iu', $rawQuery)) {
            $searchQuery .= ' cleanser foam';
        }

        return self::executeSqlSearch($searchQuery, $rawQuery, $category, $sortBy, $minPrice, $maxPrice, $limit);
    }

    /**
     * Database SQL Search
     */
    protected static function executeSqlSearch(string $searchQuery, string $rawQuery, string $category, string $sortBy, ?float $minPrice, ?float $maxPrice, int $limit): array
    {
        $keywords = array_filter(explode(' ', trim($searchQuery)), fn($w) => mb_strlen($w) >= 2);

        $productQuery = Product::query()
            ->where('status', 'active')
            ->with(['variants' => fn($q) => $q->where('is_active', true), 'category', 'brand']);

        // Only compute total sales subquery if explicitly sorting by best selling
        if ($sortBy === 'best_selling') {
            $productQuery->withSum(['orderItems as total_sales' => function ($q) {
                $q->whereHas('order', fn($o) => $o->whereNotIn('status', ['cancelled', 'returned']));
            }], 'quantity');
        }

        if (!empty($rawQuery)) {
            $productQuery->where(function ($q) use ($rawQuery, $keywords) {
                $q->where('name', 'like', "%{$rawQuery}%")
                    ->orWhere('bangla_name', 'like', "%{$rawQuery}%")
                    ->orWhere('description', 'like', "%{$rawQuery}%");

                if (!empty($keywords)) {
                    $q->orWhere(function ($sub) use ($keywords) {
                        foreach ($keywords as $word) {
                            $sub->orWhere('name', 'like', "%{$word}%")
                                ->orWhere('bangla_name', 'like', "%{$word}%");
                        }
                    });
                }
            });
        }

        if ($category !== '') {
            $productQuery->whereHas('category', fn($q) => $q->where('name', 'like', "%{$category}%"));
        }

        if ($minPrice !== null || $maxPrice !== null) {
            $productQuery->whereHas('variants', function ($q) use ($minPrice, $maxPrice) {
                if ($minPrice !== null) $q->where('price', '>=', $minPrice);
                if ($maxPrice !== null) $q->where('price', '<=', $maxPrice);
            });
        }

        // Sorting
        if ($sortBy === 'best_selling') {
            $productQuery->orderByDesc('total_sales')->orderByDesc('id');
        } elseif ($sortBy === 'popular') {
            $productQuery->orderByDesc('is_featured')->orderByDesc('id');
        } elseif ($sortBy === 'price_low_to_high') {
            $productQuery->orderBy(
                ProductVariant::select('price')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
                    ->orderBy('price', 'asc')
                    ->limit(1),
                'asc'
            );
        } elseif ($sortBy === 'price_high_to_low') {
            $productQuery->orderBy(
                ProductVariant::select('price')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('is_active', true)
                    ->orderBy('price', 'desc')
                    ->limit(1),
                'desc'
            );
        } else {
            if (!empty($rawQuery)) {
                $cleanTerms = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $rawQuery);
                $words = array_values(array_filter(explode(' ', $cleanTerms), fn($w) => mb_strlen($w) >= 2));

                if (!empty($words)) {
                    $scoreParts = [];
                    $escapedRaw = addslashes($rawQuery);
                    // Full phrase match gets top score
                    $scoreParts[] = "CASE WHEN products.name = '{$escapedRaw}' THEN 100 WHEN products.name LIKE '%{$escapedRaw}%' THEN 50 ELSE 0 END";

                    // Each matched keyword in name adds 10 points
                    foreach ($words as $word) {
                        $escapedWord = addslashes($word);
                        $scoreParts[] = "CASE WHEN products.name LIKE '%{$escapedWord}%' THEN 10 ELSE 0 END";
                    }

                    // Brand match adds 25 points
                    $brandConditions = implode(' OR ', array_map(fn($w) => "brands.name LIKE '%" . addslashes($w) . "%'", $words));
                    $scoreParts[] = "(SELECT CASE WHEN COUNT(*) > 0 THEN 25 ELSE 0 END FROM brands WHERE brands.id = products.brand_id AND ({$brandConditions}))";

                    $relevanceScoreSql = implode(' + ', $scoreParts);
                    $productQuery->orderByRaw('(' . $relevanceScoreSql . ') DESC');
                }
            }
            $productQuery->orderByDesc('id');
        }

        $products = $productQuery->limit($limit)->get();

        return [
            'count' => $products->count(),
            'products' => self::formatProducts($products),
            'source' => 'database_sql',
        ];
    }

    /**
     * Common product formatter
     */
    protected static function formatProducts($products): array
    {
        $results = [];
        foreach ($products as $product) {
            $firstVariant = $product->variants->first();
            $regularPrice = $firstVariant ? (float) $firstVariant->price : 0;
            $discountPrice = ($firstVariant && $firstVariant->discount_price) ? (float) $firstVariant->discount_price : null;
            $stock = $product->variants->sum('stock');

            $discountPercent = 0;
            if ($discountPrice && $regularPrice > $discountPrice && $regularPrice > 0) {
                $discountPercent = (int) round((($regularPrice - $discountPrice) / $regularPrice) * 100);
            }

            $results[] = [
                'id' => $product->id,
                'name' => $product->name,
                'bangla_name' => $product->bangla_name ?? '',
                'slug' => $product->slug,
                'slug_url' => $product->slug_url,
                'price' => $discountPrice ?? $regularPrice,
                'regular_price' => $regularPrice,
                'discount_price' => $discountPrice,
                'discount_percentage' => $discountPercent,
                'stock' => (int) $stock,
                'in_stock' => $stock > 0,
                'image' => $product->image ? Product::getImageUrl($product->image) : null,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'total_sales' => (int) ($product->total_sales ?? 0),
            ];
        }

        return $results;
    }
}
