<?php

namespace App\Services\AiChat\Tools;

use App\Models\Combo;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ActiveOffersTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_active_offers',
                'description' => 'Check active promotional offers, storewide discounts, combo offers, or available coupon/promo codes in Mohima. Call this whenever a customer asks about discounts, coupons, promo codes, or current offers.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'description' => 'Optional category name if customer is asking about offers on a specific category.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("ActiveOffersTool executed", ['args' => $args]);

        $category = trim($args['category'] ?? '');
        $cacheKey = 'ai_chat_active_offers_' . md5($category);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function () use ($category) {
            return self::fetchOffersData($category);
        });
    }

    protected static function fetchOffersData(string $category): array
    {
        // 1. Discounted products
        $discountQuery = Product::where('status', 'active')
            ->whereHas('variants', function ($q) {
                $q->where('is_active', true)
                  ->whereNotNull('discount_price')
                  ->whereRaw('discount_price < price')
                  ->where('discount_price', '>', 0)
                  ->where('stock', '>', 0);
            })
            ->with([
                'variants' => fn($q) => $q->where('is_active', true)->where('stock', '>', 0),
                'brand',
                'category',
            ]);

        if ($category !== '') {
            $discountQuery->whereHas('category', function ($q) use ($category) {
                $q->where('name', 'like', "%{$category}%");
            });
        }

        $discountedProducts = $discountQuery->orderBy('id', 'desc')->limit(4)->get();

        $activeDeals = [];
        $payloadProducts = [];

        foreach ($discountedProducts as $prod) {
            $firstVar = $prod->variants->first();
            $regular = (float) $firstVar->price;
            $discount = (float) $firstVar->discount_price;
            $savings = (int) round((($regular - $discount) / $regular) * 100);

            $activeDeals[] = [
                'product_name' => $prod->name,
                'regular_price' => "৳{$regular} BDT",
                'discount_price' => "৳{$discount} BDT",
                'discount_percentage' => "{$savings}% OFF",
                'brand' => $prod->brand?->name,
            ];

            $payloadProducts[] = [
                'id' => $prod->id,
                'name' => $prod->name,
                'bangla_name' => $prod->bangla_name ?? '',
                'slug' => $prod->slug,
                'slug_url' => $prod->slug_url,
                'price' => $discount,
                'regular_price' => $regular,
                'discount_price' => $discount,
                'discount_percentage' => $savings,
                'stock' => (int) $prod->variants->sum('stock'),
                'in_stock' => true,
                'image' => $prod->image ? Product::getImageUrl($prod->image) : null,
                'brand' => $prod->brand?->name,
                'category' => $prod->category?->name,
            ];
        }

        // 2. Active Coupons
        $coupons = Coupon::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->limit(3)
            ->get()
            ->map(fn($c) => [
                'code' => $c->code,
                'discount_type' => $c->type ?? 'percentage',
                'discount_value' => $c->value,
                'min_order_amount' => $c->min_order_amount,
            ]);

        // 3. Combos
        $combos = Combo::where('status', 'active')
            ->limit(2)
            ->get()
            ->map(fn($cb) => [
                'title' => $cb->title,
                'price' => (float) $cb->price,
            ]);

        return [
            'deals_count' => count($activeDeals),
            'top_discounted_products' => $activeDeals,
            'products' => $payloadProducts,
            'active_coupons' => $coupons,
            'combos' => $combos,
            'guidance' => 'Present these genuine active deals and promo coupons to the customer with enthusiasm. Encourage them to add their favorite to cart.',
        ];
    }
}
