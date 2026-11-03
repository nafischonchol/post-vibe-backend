<?php

namespace App\Services\Customer;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SitemapService
{
    public function index()
    {
        $sitemaps = Cache::remember('sitemap_index', 1800, function () {
            return [
                ['type' => 'static', 'url' => '/sitemap-static.xml', 'lastmod' => now()->toIso8601String()],
                ['type' => 'categories', 'url' => '/sitemap-category.xml', 'lastmod' => Category::where('is_active', true)->max('updated_at') ?? now()->toIso8601String()],
                ['type' => 'brands', 'url' => '/sitemap-brand.xml', 'lastmod' => Brand::where('is_active', true)->max('updated_at') ?? now()->toIso8601String()],
                ['type' => 'products', 'url' => '/sitemap-products.xml', 'lastmod' => Product::where('status', 'active')->max('updated_at') ?? now()->toIso8601String()],
            ];
        });

        return responseSuccess(['sitemaps' => $sitemaps]);
    }

    public function products(Request $request)
    {
        $products = Cache::remember('sitemap_products', 1800, function () {
            return Product::where('status', 'active')
                ->select('id', 'name', 'slug', 'image', 'updated_at')
                ->orderByDesc('updated_at')
                ->get()
                ->map(function ($product) {
                    $imageUrl = null;
                    if ($product->image) {
                        $imageUrl = str_starts_with($product->image, 'http')
                            ? $product->image
                            : url(Storage::url($product->image));
                    }

                    return [
                        'name' => $product->name,
                        'slug_url' => $product->slug_url,
                        'image' => $imageUrl,
                        'updated_at' => $product->updated_at?->toISOString() ?? now()->toISOString(),
                    ];
                });
        });

        return responseSuccess(['products' => $products]);
    }

    public function categories()
    {
        $categories = Cache::remember('sitemap_categories', 1800, function () {
            return Category::where('is_active', true)
                ->select('id', 'slug', 'updated_at')
                ->orderByDesc('updated_at')
                ->get()
                ->map(function ($cat) {
                    return [
                        'id' => $cat->id,
                        'slug' => $cat->slug_url,
                        'updated_at' => $cat->updated_at?->toISOString() ?? now()->toISOString(),
                    ];
                });
        });

        return responseSuccess(['categories' => $categories]);
    }

    public function brands()
    {
        $brands = Cache::remember('sitemap_brands', 1800, function () {
            return Brand::where('is_active', true)
                ->select('id', 'slug', 'updated_at')
                ->orderByDesc('updated_at')
                ->get()
                ->map(function ($b) {
                    return [
                        'id' => $b->id,
                        'slug' => $b->slug_url,
                        'updated_at' => $b->updated_at?->toISOString() ?? now()->toISOString(),
                    ];
                });
        });

        return responseSuccess(['brands' => $brands]);
    }
}
