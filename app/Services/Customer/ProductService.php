<?php

namespace App\Services\Customer;

use App\Enums\OrderStatusEnum;
use App\Http\Requests\Customer\ProductFilterRequest;
use App\Http\Resources\Customer\ProductDetailsResource;
use App\Http\Resources\Customer\ProductResource;
use App\Jobs\LogSearchJob;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Combo;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ProductService
{
    private function getPaginationParams(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 20);
        if ($perPage > 100) {
            $perPage = 100;
        }

        $page = (int) $request->input('page', 1);
        if ($page > 50) {
            $page = 50;
        }

        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = 1;
        }

        return [$page, $perPage];
    }

    public function popularProducts(Request $request)
    {
        [$page, $perPage] = $this->getPaginationParams($request);
        $cacheKey = "popular_products_p{$page}_l{$perPage}";

        if (Cache::has($cacheKey)) {
            $response = Cache::get($cacheKey);
        } else {
            $products = Product::where('status', 'active')
                ->with(['variants', 'category', 'brand'])
                ->withSum(['orderItems as total_sales' => function ($query) {
                    $query->whereHas('order', function ($q) {
                        $q->where('created_at', '>=', now()->subDays(40))
                            ->whereNotIn('status', [
                                OrderStatusEnum::CANCELLED->value,
                                OrderStatusEnum::RETURNED->value,
                            ]);
                    });
                }], 'quantity')
                ->orderByDesc('total_sales')
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page);

            $response = [
                'items' => ProductResource::collection($products),
                'pagination' => pagination($products),
            ];

            Cache::put($cacheKey, $response, now()->addHours(2));
        }

        return responseSuccess($response);
    }

    public function newArrivalProducts(Request $request)
    {
        [$page, $perPage] = $this->getPaginationParams($request);
        $cacheKey = "new_arrival_products_p{$page}_l{$perPage}";

        if (Cache::has($cacheKey)) {
            $response = Cache::get($cacheKey);
        } else {
            $products = Product::where('status', 'active')
                ->with(['variants', 'category', 'brand'])
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], 'page', $page);

            $response = [
                'items' => ProductResource::collection($products),
                'pagination' => pagination($products),
            ];

            Cache::put($cacheKey, $response, now()->addHours(2));
        }

        return responseSuccess($response);
    }

    public function show($slug_url)
    {
        $id = extractIdFromSlug($slug_url);
        $cacheKey = "product_details_{$id}";

        if (Cache::has($cacheKey)) {
            $product = Cache::get($cacheKey);
        } else {
            $product = Product::where('status', 'active')
                ->with([
                    'category' => function ($query) {
                        $query->with('ancestors');
                    },
                    'brand',
                    'specifications.attribute',
                    'variants.attributeValues.attribute',
                    'images',
                    'landingConfig',
                    'tierOffers' => function ($q) {
                        $q->where('is_active', true)->orderBy('sort_order', 'asc')->orderBy('quantity', 'asc');
                    },
                    'tierOffers.giftProduct:id,name',
                    'tierOffers.giftVariant:id,sku,price',
                    'landingVideos' => function ($q) {
                        $q->where('is_active', true)->orderBy('sort_order', 'asc');
                    },
                    'landingVideos.featuredProduct:id,name,slug,image,brand_id',
                    'landingVideos.featuredProduct.variants:id,product_id,price,discount_price',
                    'landingVideos.featuredProduct.brand:id,name',
                ])
                ->findOrFail($id);

            Cache::put($cacheKey, $product, now()->addHours(2));
        }

        return responseSuccess(ProductDetailsResource::make($product));
    }

    public function brandProducts(Request $request, $brandId)
    {
        [$page, $perPage] = $this->getPaginationParams($request);

        $products = Product::where('status', 'active')
            ->where('brand_id', $brandId)
            ->with(['variants', 'category', 'brand'])
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return responseSuccess([
            'items' => ProductResource::collection($products),
            'pagination' => pagination($products),
        ]);
    }

    public function categoryProducts(Request $request, $categoryId)
    {
        [$page, $perPage] = $this->getPaginationParams($request);

        $products = Product::where('status', 'active')
            ->where('category_id', $categoryId)
            ->with(['variants', 'category', 'brand'])
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return responseSuccess([
            'items' => ProductResource::collection($products),
            'pagination' => pagination($products),
        ]);
    }

    public function filterProducts(ProductFilterRequest $request)
    {
        [$page, $perPage] = $this->getPaginationParams($request);

        if ($request->input('category_slug') === 'combo') {
            return $this->filterCombos($request, $page, $perPage);
        }

        $query = Product::where('status', 'active')
            ->with(['variants', 'category', 'brand']);

        if ($request->filled('search_text')) {
            $searchText = $request->input('search_text');
            $query->where(function ($q) use ($searchText) {
                $q->where('name', 'like', "%{$searchText}%")
                    ->orWhere('bangla_name', 'like', "%{$searchText}%")
                    ->orWhereHas('category', function ($catQ) use ($searchText) {
                        $catQ->where('name', 'like', "%{$searchText}%");
                    })
                    ->orWhereHas('brand', function ($brandQ) use ($searchText) {
                        $brandQ->where('name', 'like', "%{$searchText}%");
                    });
            });
        }

        $seoData = null;

        if ($request->filled('category_slug')) {
            $category = Category::where('slug', $request->input('category_slug'))->first();
            if ($category) {
                $categoryIds = $category->descendantsAndSelf()->pluck('id');
                $query->whereIn('category_id', $categoryIds);
                $meta_title = $category->meta_title ?? $category->name;
                $seoData = [
                    'type' => 'category',
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'meta_title' => $meta_title.'-'.config('app.name'),
                    'meta_keyword' => $category->meta_keyword,
                    'meta_description' => $category->meta_description,
                    'meta_image' => $category->meta_image,
                    'seo_bottom_content' => $category->seo_bottom_content,
                    'seo_bottom_content_bn' => $category->seo_bottom_content_bn,
                    'faqs' => $category->faqs ?? [],
                    'faqs_bn' => $category->faqs_bn ?? [],
                ];
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('brand_slug')) {
            $brand = Brand::where('slug', $request->input('brand_slug'))->first();
            if ($brand) {
                $query->where('brand_id', $brand->id);
                $meta_title = $brand->meta_title ?? $brand->name;
                if (! $seoData) {
                    $seoData = [
                        'type' => 'brand',
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'meta_title' => $meta_title.'-'.config('app.name'),
                        'meta_keyword' => $brand->meta_keyword,
                        'meta_description' => $brand->meta_description,
                        'meta_image' => $brand->meta_image,
                        'seo_bottom_content' => $brand->seo_bottom_content,
                        'seo_bottom_content_bn' => $brand->seo_bottom_content_bn,
                        'faqs' => $brand->faqs ?? [],
                        'faqs_bn' => $brand->faqs_bn ?? [],
                    ];
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('concern_id')) {
            $concernId = $request->input('concern_id');
            $query->whereHas('specifications', function ($specQ) use ($concernId) {
                $specQ->whereHas('predefinedValues', function ($pvQ) use ($concernId) {
                    $pvQ->where('attribute_values.id', $concernId);
                });
            });
        }

        if ($request->filled('attribute_value_id')) {
            $attrValIds = is_array($request->input('attribute_value_id'))
                ? $request->input('attribute_value_id')
                : explode(',', $request->input('attribute_value_id'));

            $query->where(function ($q) use ($attrValIds) {
                $q->whereHas('specifications', function ($specQ) use ($attrValIds) {
                    $specQ->whereHas('predefinedValues', function ($pvQ) use ($attrValIds) {
                        $pvQ->whereIn('attribute_values.id', $attrValIds);
                    });
                })->orWhereHas('variants', function ($vQ) use ($attrValIds) {
                    $vQ->whereHas('attributeValues', function ($avQ) use ($attrValIds) {
                        $avQ->whereIn('attribute_values.id', $attrValIds);
                    });
                });
            });
        }

        if ($request->filled('min_price')) {
            $minPrice = (float) $request->input('min_price');
            $query->whereHas('variants', function ($q) use ($minPrice) {
                $q->where('price', '>=', $minPrice);
            });
        }

        if ($request->filled('max_price')) {
            $maxPrice = (float) $request->input('max_price');
            $query->whereHas('variants', function ($q) use ($maxPrice) {
                $q->where('price', '<=', $maxPrice);
            });
        }

        if ($request->filled('is_stock')) {
            $isStock = filter_var($request->input('is_stock'), FILTER_VALIDATE_BOOLEAN);
            if ($isStock) {
                $query->whereHas('variants', function ($q) {
                    $q->where('stock', '>', 0);
                });
            }
        }

        if ($request->input('sort_by') === 'best_selling') {
            $query->withSum(['orderItems as total_sales' => function ($q) {
                $q->whereHas('order', function ($ordQ) {
                    $ordQ->where('created_at', '>=', now()->subDays(40))
                        ->whereNotIn('status', [
                            OrderStatusEnum::CANCELLED->value,
                            OrderStatusEnum::RETURNED->value,
                        ]);
                });
            }], 'quantity')
                ->orderByDesc('total_sales')
                ->orderByDesc('id');
        } elseif ($request->input('sort_by') === 'price_low_to_high') {
            $query->addSelect([
                'min_variant_price' => ProductVariant::select('price')
                    ->whereColumn('product_id', 'products.id')
                    ->active()
                    ->orderBy('price', 'asc')
                    ->limit(1),
            ])->orderBy('min_variant_price', 'asc')->orderByDesc('id');
        } elseif ($request->input('sort_by') === 'price_high_to_low') {
            $query->addSelect([
                'min_variant_price' => ProductVariant::select('price')
                    ->whereColumn('product_id', 'products.id')
                    ->active()
                    ->orderBy('price', 'asc')
                    ->limit(1),
            ])->orderBy('min_variant_price', 'desc')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $products = $query->paginate($perPage, ['*'], 'page', $page);

        if ($request->filled('search_text')) {
            $searchText = trim((string) $request->input('search_text'));
            if ($searchText !== '') {
                $visitorId = $request->header('X-Visitor-Id') ?: ($request->input('visitor_id') ?: $request->input('guest_token') ?: $request->cookie('visitor_id'));
                $clientId = auth('sanctum')->id();

                LogSearchJob::dispatch(
                    query: $searchText,
                    resultsCount: $products->total(),
                    source: 'catalog_page',
                    visitorId: $visitorId,
                    clientId: $clientId,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent()
                );
            }
        }

        return responseSuccess([
            'items' => [
                'products' => ProductResource::collection($products),
                'seo' => $seoData,
            ],
            'pagination' => pagination($products),
        ]);
    }

    public function filterCombos(ProductFilterRequest $request, int $page, int $perPage)
    {
        $query = Combo::where('is_active', true)
            ->with(['items.product.brand', 'items.variant']);

        if ($request->filled('search_text')) {
            $searchText = trim((string) $request->input('search_text'));
            $query->where(function ($q) use ($searchText) {
                $q->where('name', 'like', "%{$searchText}%")
                    ->orWhere('bangla_name', 'like', "%{$searchText}%")
                    ->orWhere('short_description', 'like', "%{$searchText}%")
                    ->orWhereHas('items.product', function ($itemQ) use ($searchText) {
                        $itemQ->where('name', 'like', "%{$searchText}%")
                            ->orWhere('bangla_name', 'like', "%{$searchText}%");
                    });
            });
        }

        if ($request->filled('brand_slug')) {
            $brandSlug = $request->input('brand_slug');
            $query->whereHas('items.product.brand', function ($brandQ) use ($brandSlug) {
                $brandQ->where('slug', $brandSlug);
            });
        }

        if ($request->filled('min_price')) {
            $query->where('combo_price', '>=', (float) $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('combo_price', '<=', (float) $request->input('max_price'));
        }

        $sortBy = $request->input('sort_by', 'latest');
        if ($sortBy === 'price_low_to_high') {
            $query->orderBy('combo_price', 'asc')->orderByDesc('id');
        } elseif ($sortBy === 'price_high_to_low') {
            $query->orderBy('combo_price', 'desc')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $combos = $query->paginate($perPage, ['*'], 'page', $page);

        $inStockOnly = $request->filled('is_stock') && in_array($request->input('is_stock'), [1, '1', true, 'true'], true);

        $mappedCombos = $combos->getCollection()->filter(function ($combo) use ($inStockOnly) {
            if ($inStockOnly && $combo->stock <= 0) {
                return false;
            }
            return true;
        })->map(function ($combo) {
            $firstProductBrand = $combo->items->first()?->product?->brand;
            return [
                'id' => $combo->id,
                'is_combo' => true,
                'name' => $combo->name,
                'bangla_name' => $combo->bangla_name,
                'slug' => $combo->slug,
                'slug_url' => $combo->slug_url,
                'category' => [
                    'id' => 'combo',
                    'name' => 'Combo Offers',
                    'slug' => 'combo',
                ],
                'brand' => $firstProductBrand ? [
                    'id' => $firstProductBrand->id,
                    'name' => $firstProductBrand->name,
                ] : null,
                'thumbnail' => $combo->image ? (str_starts_with($combo->image, 'http') ? $combo->image : Storage::url($combo->image)) : null,
                'blur_image' => $combo->blur_image,
                'has_variants' => false,
                'product_variant_id' => null,
                'price' => (float) $combo->combo_price,
                'discount_price' => (float) $combo->combo_price,
                'min_price' => (float) $combo->regular_price,
                'max_price' => (float) $combo->regular_price,
                'min_discount_price' => (float) $combo->combo_price,
                'max_discount_price' => (float) $combo->combo_price,
                'total_stock' => (int) $combo->stock,
                'stock' => (int) $combo->stock,
                'save_amount' => (float) $combo->save_amount,
                'save_percentage' => (int) $combo->save_percentage,
                'items_count' => $combo->items->count(),
                'status' => $combo->is_active ? 'active' : 'inactive',
                'rating' => 5.0,
                'reviews_count' => 0,
            ];
        })->values();

        $seoData = [
            'type' => 'category',
            'id' => 'combo',
            'name' => 'Combo Offers',
            'slug' => 'combo',
            'meta_title' => 'Beauty & Skincare Combo Offers - '.config('app.name'),
            'meta_keyword' => 'combo, routine set, bundle, k-beauty combo',
            'meta_description' => 'Explore authentic skincare combos, curated K-Beauty routine bundles, and high-value beauty packs at discounted package prices.',
            'meta_image' => null,
        ];

        return responseSuccess([
            'items' => [
                'products' => $mappedCombos,
                'seo' => $seoData,
            ],
            'pagination' => pagination($combos),
        ]);
    }

    public function getFilterableData()
    {
        return Cache::remember('customer_filterable_data', now()->addHours(2), function () {
            $categories = Category::where('is_active', true)
                ->whereNull('parent_id')
                ->with(['children' => function ($q) {
                    $q->select('id', 'name', 'slug', 'parent_id');
                    $q->where('is_active', true);
                }])
                ->select(['id', 'name', 'slug', 'parent_id'])
                ->get();

            $comboCount = Combo::where('is_active', true)->count();
            if ($comboCount > 0) {
                $categories->push((object) [
                    'id' => 'combo',
                    'name' => 'Combo Offers',
                    'slug' => 'combo',
                    'parent_id' => null,
                    'children' => collect([]),
                ]);
            }

            $brands = Brand::where('is_active', true)
                ->select(['id', 'name', 'slug'])
                ->get();

            $attributes = Attribute::where('is_active', true)
                ->whereIn('type', [Attribute::TYPE['SELECT'], Attribute::TYPE['MULTI_SELECT']])
                ->with(['attributeValues' => function ($q) {
                    $q->where('is_active', true)->select(['id', 'attribute_id', 'value', 'image']);
                }])
                ->select(['id', 'name', 'slug', 'type'])
                ->get();

            $minPrice = ProductVariant::active()->min('price') ?? 0;
            $maxPrice = ProductVariant::active()->max('price') ?? 5000;

            return responseSuccess([
                'categories' => $categories,
                'brands' => $brands,
                'attributes' => $attributes,
                'price_range' => [
                    'min' => (float) $minPrice,
                    'max' => (float) $maxPrice,
                ],
            ]);
        });
    }

    public function searchSuggestions(Request $request)
    {
        $q = trim((string) ($request->input('q') ?? $request->input('search_text') ?? ''));

        if ($q === '') {
            return responseSuccess([]);
        }

        $products = Product::where('status', 'active')
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('bangla_name', 'like', "%{$q}%")
                    ->orWhereHas('category', function ($catQ) use ($q) {
                        $catQ->where('name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('brand', function ($brandQ) use ($q) {
                        $brandQ->where('name', 'like', "%{$q}%");
                    });
            })
            ->with([
                'category:id,name',
                'brand:id,name',
                'variants:id,product_id,price,discount_price',
            ])
            ->select(['id', 'name', 'bangla_name', 'slug', 'image', 'category_id', 'brand_id'])
            ->limit(10)
            ->get();

        $items = $products->map(function ($product) {
            $variants = $product->variants;
            $minPrice = $variants->isNotEmpty() ? $variants->min('price') : 0;
            $minDiscountPrice = $variants->isNotEmpty() ? $variants->min('discount_price') : 0;

            $price = ($minDiscountPrice && $minDiscountPrice > 0) ? $minDiscountPrice : $minPrice;
            $originalPrice = ($minDiscountPrice && $minDiscountPrice > 0) ? $minPrice : null;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'bangla_name' => $product->bangla_name,
                'slug' => $product->slug,
                'slug_url' => $product->slug_url,
                'image' => $product->image ? Storage::url($product->image) : null,
                'price' => (float) $price,
                'original_price' => $originalPrice ? (float) $originalPrice : null,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
            ];
        });

        return responseSuccess($items);
    }
}
