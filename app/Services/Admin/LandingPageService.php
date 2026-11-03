<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\CategoryLandingConfig;
use App\Models\LandingPageVideo;
use App\Models\LandingTierOffer;
use App\Models\Product;
use App\Models\ProductLandingConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingPageService
{
    /**
     * Get all categories for Category Landing Page management.
     */
    public function getCategoryLandingPages(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $query = Category::where('is_active', true)
            ->withCount(['products' => function ($q) {
                $q->where('status', 'active');
            }]);

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");
        }

        $categories = $query->orderBy('name')->get();

        $items = $categories->map(function ($cat) {
            $isCustomized = ! empty($cat->landing_headline) ||
                ! empty($cat->landing_subtitle) ||
                ! empty($cat->landing_video_url) ||
                ! empty($cat->landing_badge) ||
                (! empty($cat->landing_benefits) && is_array($cat->landing_benefits) && count($cat->landing_benefits) > 0) ||
                (! empty($cat->landing_steps) && is_array($cat->landing_steps) && count($cat->landing_steps) > 0);

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'icon' => $cat->icon ? Product::getImageUrl($cat->icon) : null,
                'products_count' => (int) $cat->products_count,
                'is_customized' => $isCustomized,
                'landing_headline' => $cat->landing_headline,
                'landing_badge' => $cat->landing_badge,
                'landing_video_url' => $cat->landing_video_url,
                'landing_url' => "/clp/{$cat->slug}",
                'updated_at' => $cat->updated_at?->format('Y-m-d H:i') ?? null,
            ];
        });

        return responseSuccess($items, 'Category landing pages retrieved successfully');
    }

    /**
     * Standard default common questions / FAQs across landing pages.
     */
    public static function getDefaultFaqs(): array
    {
        return [
            [
                'question' => 'কীভাবে অর্ডার করব?',
                'answer' => 'পছন্দের প্রোডাক্ট নির্বাচন করে নিচের সহজ ফর্মে নাম, ফোন নম্বর ও পূর্ণ ঠিকানা দিয়ে "অর্ডার কনফার্ম করুন" বাটনে ক্লিক করুন। কোনো অ্যাকাউন্ট বা রেজিস্ট্রেশন লাগবে না।',
            ],
            [
                'question' => 'পণ্য আসল বা অরিজিনাল তো?',
                'answer' => 'হ্যাঁ, আমাদের প্রতিটি পণ্য ১০০% অরিজিনাল এবং সরাসরি অফিশিয়াল সোর্স থেকে সংগৃহীত। ডেলিভারি পাওয়ার পর পার্সেল চেক করে নেওয়ার সম্পূর্ণ সুযোগ রয়েছে।',
            ],
            [
                'question' => 'ডেলিভারি চার্জ কত এবং কতদিনে পাব?',
                'answer' => 'ঢাকার ভিতরে ডেলিভারি চার্জ ৬০ টাকা (১-২ দিন) এবং ঢাকার বাইরে ১২০ টাকা (২-৪ দিন)। স্পেশাল অফারে ফ্রি ডেলিভারিও প্রযোজ্য হতে পারে।',
            ],
            [
                'question' => 'পণ্য হাতে পেয়ে পেমেন্ট করতে পারব?',
                'answer' => 'অবশ্যই! আমাদের ক্যাশ অন ডেলিভারি সুবিধা রয়েছে। পার্সেল হাতে পেয়ে ডেলিভারিম্যানকে মূল্য পরিশোধ করতে পারবেন। চাইলে বিকাশ সেন্ড মানিও করতে পারেন।',
            ],
        ];
    }

    /**
     * Get single category landing page config.
     */
    public function getCategoryLandingDetail(int $id): JsonResponse
    {
        $cat = Category::with([
            'landingConfig',
            'landingVideos' => fn ($q) => $q->orderBy('sort_order', 'asc'),
            'landingVideos.featuredProduct:id,name,slug,image,brand_id',
            'landingVideos.featuredProduct.variants:id,product_id,price,discount_price',
            'landingVideos.featuredProduct.brand:id,name',
        ])->find($id);

        if (! $cat) {
            return responseError('Category not found', 404);
        }

        $defaultFaqs = self::getDefaultFaqs();

        $isCustomized = ! empty($cat->landing_headline) ||
            ! empty($cat->landing_subtitle) ||
            ! empty($cat->landing_video_url) ||
            ! empty($cat->landing_badge) ||
            (! empty($cat->landing_benefits) && is_array($cat->landing_benefits) && count($cat->landing_benefits) > 0) ||
            (! empty($cat->landing_steps) && is_array($cat->landing_steps) && count($cat->landing_steps) > 0) ||
            (! empty($cat->faqs) && is_array($cat->faqs) && count($cat->faqs) > 0) ||
            $cat->landingVideos->isNotEmpty() ||
            $cat->landingConfig !== null;

        $defaultBenefits = [
            [
                'title' => 'সারা দেশে দ্রুত ডেলিভারি',
                'desc' => 'ঢাকা ও ঢাকার বাইরে ক্যাশ অন ডেলিভারি সুবিধা',
            ],
            [
                'title' => '১০০% অরিজিনাল পণ্য',
                'desc' => 'সরাসরি ব্র্যান্ড ও অথেনটিক সোর্স থেকে আমদানিকৃত',
            ],
            [
                'title' => 'ক্যাশ অন ডেলিভারি',
                'desc' => 'পণ্য হাতে পেয়ে চেক করে পেমেন্ট করার সুবিধা',
            ],
            [
                'title' => 'সহজ রিটার্ন পলিসি',
                'desc' => 'কোনো সমস্যা হলে দ্রুত রিপ্লেসমেন্ট ও কাস্টমার সাপোর্ট',
            ],
        ];

        $defaultSteps = [
            [
                'step' => '১',
                'title' => 'পণ্য সিলেক্ট করুন',
                'desc' => 'আপনার পছন্দের প্রোডাক্ট বেছে নিয়ে অর্ডার ফর্মে যান',
            ],
            [
                'step' => '২',
                'title' => 'অর্ডার কনফার্ম করুন',
                'desc' => 'নাম, মোবাইল নাম্বার ও ঠিকানা দিয়ে সহজে অর্ডার সম্পন্ন করুন',
            ],
            [
                'step' => '৩',
                'title' => 'পণ্য হাতে বুঝে নিন',
                'desc' => 'দ্রুততম সময়ে ডেলিভারি পেয়ে চেক করে মূল্য পরিশোধ করুন',
            ],
        ];

        // Format landing videos with shoppable featured product info
        $landingVideos = $cat->landingVideos->map(function ($lv) {
            $fp = $lv->featuredProduct;
            $price = 0;
            if ($fp) {
                $firstVar = $fp->variants->first();
                $price = $firstVar ? ($firstVar->discount_price > 0 ? (float) $firstVar->discount_price : (float) $firstVar->price) : 0;
            }

            return [
                'id' => $lv->id,
                'video_url' => $lv->video_url,
                'video_id' => $lv->video_id,
                'thumbnail_url' => $lv->thumbnail_url,
                'embed_url' => $lv->embed_url,
                'title' => $lv->title,
                'badge_text' => $lv->badge_text,
                'featured_product_id' => $lv->featured_product_id,
                'featured_product' => $fp ? [
                    'id' => $fp->id,
                    'name' => $fp->name,
                    'slug' => $fp->slug,
                    'brand_name' => $fp->brand?->name,
                    'price' => $price,
                    'thumbnail' => Product::getImageUrl($fp->image),
                ] : null,
                'sort_order' => (int) $lv->sort_order,
            ];
        });

        // Available active products in this category for the dropdown
        $descendantCategoryIds = $cat->descendantsAndSelf()->pluck('id');
        $availableProducts = Product::whereIn('category_id', $descendantCategoryIds)
            ->where('status', 'active')
            ->with(['brand:id,name', 'variants:id,product_id,price,discount_price'])
            ->select(['id', 'name', 'slug', 'image', 'brand_id'])
            ->limit(50)
            ->get()
            ->map(function ($p) {
                $firstVar = $p->variants->first();
                $price = $firstVar ? ($firstVar->discount_price > 0 ? (float) $firstVar->discount_price : (float) $firstVar->price) : 0;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'brand_name' => $p->brand?->name,
                    'price' => $price,
                    'thumbnail' => Product::getImageUrl($p->image),
                ];
            });

        return responseSuccess([
            'id' => $cat->id,
            'name' => $cat->name,
            'slug' => $cat->slug,
            'is_customized' => $isCustomized,
            'landing_headline' => $cat->landing_headline,
            'landing_subtitle' => $cat->landing_subtitle,
            'landing_badge' => $cat->landing_badge,
            'landing_video_url' => $cat->landing_video_url,
            'landing_benefits' => is_array($cat->landing_benefits) ? $cat->landing_benefits : [],
            'landing_steps' => is_array($cat->landing_steps) ? $cat->landing_steps : [],
            'landing_config' => $cat->landingConfig,
            'landing_videos' => $landingVideos,
            'available_products' => $availableProducts,
            'default_headline' => "সেরা মানের {$cat->name} কালেকশন — বিশেষ ছাড়ে আজই অর্ডার করুন",
            'default_subtitle' => "আমাদের প্রিমিয়াম কোয়ালিটি কালেকশন থেকে আপনার পছন্দের প্রোডাক্ট বেছে নিন। দ্রুততম ডেলিভারি ও ১০০% অরিজিনাল পণ্যের নিশ্চয়তা।",
            'default_badge' => 'প্রিমিয়াম কোয়ালিটি গ্যারান্টি',
            'default_video_url' => '',
            'default_benefits' => $defaultBenefits,
            'default_steps' => $defaultSteps,
            'default_faqs' => $defaultFaqs,
            'faqs' => is_array($cat->faqs) && count($cat->faqs) > 0 ? $cat->faqs : (is_array($cat->faqs_bn) && count($cat->faqs_bn) > 0 ? $cat->faqs_bn : []),
            'faqs_bn' => is_array($cat->faqs_bn) ? $cat->faqs_bn : [],
        ]);
    }

    /**
     * Update category landing page config.
     */
    public function updateCategoryLanding(int $id, array $data): JsonResponse
    {
        $cat = Category::find($id);
        if (! $cat) {
            return responseError('Category not found', 404);
        }

        $benefits = $data['landing_benefits'] ?? null;
        if (is_string($benefits)) {
            $benefits = json_decode($benefits, true);
        }

        $steps = $data['landing_steps'] ?? null;
        if (is_string($steps)) {
            $steps = json_decode($steps, true);
        }

        $faqs = $data['faqs'] ?? null;
        if (is_string($faqs)) {
            $faqs = json_decode($faqs, true);
        }

        $cat->update([
            'landing_headline' => ! empty($data['landing_headline']) ? trim($data['landing_headline']) : null,
            'landing_subtitle' => ! empty($data['landing_subtitle']) ? trim($data['landing_subtitle']) : null,
            'landing_badge' => ! empty($data['landing_badge']) ? trim($data['landing_badge']) : null,
            'landing_video_url' => ! empty($data['landing_video_url']) ? trim($data['landing_video_url']) : null,
            'landing_benefits' => is_array($benefits) && count($benefits) > 0 ? $benefits : null,
            'landing_steps' => is_array($steps) && count($steps) > 0 ? $steps : null,
            'faqs' => is_array($faqs) && count($faqs) > 0 ? $faqs : null,
        ]);

        if (isset($data['landing_config']) && is_array($data['landing_config'])) {
            $cfg = $data['landing_config'];
            CategoryLandingConfig::updateOrCreate(
                ['category_id' => $cat->id],
                [
                    'timer_enabled' => ! empty($cfg['timer_enabled']),
                    'timer_end_time' => ! empty($cfg['timer_end_time']) ? $cfg['timer_end_time'] : null,
                    'timer_title' => ! empty($cfg['timer_title']) ? trim($cfg['timer_title']) : 'স্পেশাল অফার শেষ হতে বাকি',
                    'whatsapp_order_enabled' => ! empty($cfg['whatsapp_order_enabled']),
                    'whatsapp_custom_number' => ! empty($cfg['whatsapp_custom_number']) ? trim($cfg['whatsapp_custom_number']) : null,
                    'whatsapp_custom_message' => ! empty($cfg['whatsapp_custom_message']) ? trim($cfg['whatsapp_custom_message']) : null,
                    'recent_sales_ticker_enabled' => ! empty($cfg['recent_sales_ticker_enabled']),
                ]
            );
        }

        // Sync shoppable landing videos
        if (isset($data['landing_videos']) && is_array($data['landing_videos'])) {
            $this->syncLandingVideos($data['landing_videos'], null, $cat->id);
        }

        return responseSuccess($cat->fresh(['landingConfig', 'landingVideos']), 'Category landing page updated successfully');
    }

    /**
     * Reset category landing page to default (clear custom fields).
     */
    public function resetCategoryLanding(int $id): JsonResponse
    {
        $cat = Category::find($id);
        if (! $cat) {
            return responseError('Category not found', 404);
        }

        $cat->update([
            'landing_headline' => null,
            'landing_subtitle' => null,
            'landing_badge' => null,
            'landing_video_url' => null,
            'landing_benefits' => null,
            'landing_steps' => null,
            'faqs' => null,
        ]);

        CategoryLandingConfig::where('category_id', $cat->id)->delete();

        return responseSuccess(null, 'Category landing page reset to default successfully');
    }

    /**
     * Get paginated products for Product Landing Page management.
     */
    public function getProductLandingPages(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $search = trim((string) $request->input('search', ''));
        $categoryId = $request->input('category_id');

        $query = Product::with([
            'category:id,name',
            'variants' => function ($q) {
                $q->active()->select(['id', 'product_id', 'price', 'discount_price', 'stock']);
            },
        ])->select([
            'id',
            'name',
            'slug',
            'image',
            'category_id',
            'landing_headline',
            'landing_subtitle',
            'landing_badge',
            'landing_video_url',
            'landing_benefits',
            'landing_features',
            'status',
            'updated_at',
        ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->latest('id')->paginate($perPage);

        $items = $products->getCollection()->map(function ($prod) {
            $variants = $prod->variants;
            $minPrice = $variants->isNotEmpty() ? (float) $variants->min('price') : 0;
            $minDiscountPrice = $variants->isNotEmpty() ? (float) $variants->min('discount_price') : 0;
            $price = ($minDiscountPrice > 0 && $minDiscountPrice < $minPrice) ? $minDiscountPrice : $minPrice;

            $isCustomized = ! empty($prod->landing_headline) ||
                ! empty($prod->landing_subtitle) ||
                ! empty($prod->landing_badge) ||
                ! empty($prod->landing_video_url) ||
                (! empty($prod->landing_benefits) && is_array($prod->landing_benefits) && count($prod->landing_benefits) > 0) ||
                (! empty($prod->landing_features) && is_array($prod->landing_features) && count($prod->landing_features) > 0);

            return [
                'id' => $prod->id,
                'name' => $prod->name,
                'slug' => $prod->slug,
                'slug_url' => $prod->slug_url,
                'thumbnail' => Product::getImageUrl($prod->image),
                'category_name' => $prod->category?->name ?? 'Uncategorized',
                'price' => $price,
                'is_customized' => $isCustomized,
                'landing_headline' => $prod->landing_headline,
                'landing_badge' => $prod->landing_badge,
                'landing_video_url' => $prod->landing_video_url,
                'landing_url' => "/lp/{$prod->slug_url}",
                'status' => $prod->status?->value ?? $prod->status,
                'updated_at' => $prod->updated_at?->format('Y-m-d H:i') ?? null,
            ];
        });

        return responseSuccess([
            'items' => $items,
            'pagination' => pagination($products),
        ], 'Product landing pages retrieved successfully');
    }

    /**
     * Get single product landing page config.
     */
    public function getProductLandingDetail(int $id): JsonResponse
    {
        $prod = Product::with([
            'category:id,name',
            'brand:id,name',
            'landingConfig',
            'tierOffers.giftProduct:id,name',
            'tierOffers.giftVariant:id,sku,price',
            'landingVideos' => fn ($q) => $q->orderBy('sort_order', 'asc'),
            'landingVideos.featuredProduct:id,name,slug,image,brand_id',
            'landingVideos.featuredProduct.variants:id,product_id,price,discount_price',
            'landingVideos.featuredProduct.brand:id,name',
        ])->find($id);
        if (! $prod) {
            return responseError('Product not found', 404);
        }

        $defaultFaqs = self::getDefaultFaqs();

        $isCustomized = ! empty($prod->landing_headline) ||
            ! empty($prod->landing_subtitle) ||
            ! empty($prod->landing_badge) ||
            ! empty($prod->landing_video_url) ||
            (! empty($prod->landing_benefits) && is_array($prod->landing_benefits) && count($prod->landing_benefits) > 0) ||
            (! empty($prod->landing_features) && is_array($prod->landing_features) && count($prod->landing_features) > 0) ||
            (! empty($prod->landing_faqs) && is_array($prod->landing_faqs) && count($prod->landing_faqs) > 0) ||
            $prod->landingConfig !== null ||
            $prod->tierOffers->isNotEmpty() ||
            $prod->landingVideos->isNotEmpty();

        $defaultVideo = $prod->authenticity_video_url ?: ($prod->youtube_video_urls[0] ?? '');

        $defaultBenefits = [
            [
                'title' => '১০০% অরিজিনাল',
                'desc' => 'অথেনটিক ও জেনুইন পণ্য',
            ],
            [
                'title' => 'ক্যাশ অন ডেলিভারি',
                'desc' => 'সারা বাংলাদেশে সুবিধা',
            ],
            [
                'title' => 'চেক করে পেমেন্ট',
                'desc' => 'পার্সেল দেখে টাকা দিন',
            ],
            [
                'title' => 'সহজ রিটার্ন',
                'desc' => 'কোনো সমস্যা হলে সমাধান',
            ],
        ];

        // Format landing videos with shoppable featured product info
        $landingVideos = $prod->landingVideos->map(function ($lv) {
            $fp = $lv->featuredProduct;
            $price = 0;
            if ($fp) {
                $firstVar = $fp->variants->first();
                $price = $firstVar ? ($firstVar->discount_price > 0 ? (float) $firstVar->discount_price : (float) $firstVar->price) : 0;
            }

            return [
                'id' => $lv->id,
                'video_url' => $lv->video_url,
                'video_id' => $lv->video_id,
                'thumbnail_url' => $lv->thumbnail_url,
                'embed_url' => $lv->embed_url,
                'title' => $lv->title,
                'badge_text' => $lv->badge_text,
                'featured_product_id' => $lv->featured_product_id,
                'featured_product' => $fp ? [
                    'id' => $fp->id,
                    'name' => $fp->name,
                    'slug' => $fp->slug,
                    'brand_name' => $fp->brand?->name,
                    'price' => $price,
                    'thumbnail' => Product::getImageUrl($fp->image),
                ] : null,
                'sort_order' => (int) $lv->sort_order,
            ];
        });

        // Available products for dropdown selection (current product + active products in same category)
        $catId = $prod->category_id;
        $availableProducts = Product::where('status', 'active')
            ->where(function ($q) use ($prod, $catId) {
                $q->where('id', $prod->id);
                if ($catId) {
                    $q->orWhere('category_id', $catId);
                }
            })
            ->with(['brand:id,name', 'variants:id,product_id,price,discount_price'])
            ->select(['id', 'name', 'slug', 'image', 'brand_id'])
            ->limit(40)
            ->get()
            ->map(function ($p) {
                $firstVar = $p->variants->first();
                $price = $firstVar ? ($firstVar->discount_price > 0 ? (float) $firstVar->discount_price : (float) $firstVar->price) : 0;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'brand_name' => $p->brand?->name,
                    'price' => $price,
                    'thumbnail' => Product::getImageUrl($p->image),
                ];
            });

        return responseSuccess([
            'id' => $prod->id,
            'name' => $prod->name,
            'slug' => $prod->slug,
            'slug_url' => $prod->slug_url,
            'thumbnail' => Product::getImageUrl($prod->image),
            'category_name' => $prod->category?->name,
            'is_customized' => $isCustomized,
            'landing_headline' => $prod->landing_headline,
            'landing_subtitle' => $prod->landing_subtitle,
            'landing_badge' => $prod->landing_badge,
            'landing_video_url' => $prod->landing_video_url,
            'landing_benefits' => is_array($prod->landing_benefits) ? $prod->landing_benefits : [],
            'landing_features' => is_array($prod->landing_features) ? $prod->landing_features : [],
            'landing_faqs' => is_array($prod->landing_faqs) ? $prod->landing_faqs : [],
            'landing_config' => $prod->landingConfig,
            'tier_offers' => $prod->tierOffers,
            'landing_videos' => $landingVideos,
            'available_products' => $availableProducts,
            'default_headline' => $prod->name,
            'default_subtitle' => $prod->short_description ?: "১০০% অরিজিনাল {$prod->name} কিনুন সেরা মূল্যে। সারা দেশে ক্যাশ অন ডেলিভারি এবং দ্রুত হোম ডেলিভারি সুবিধা।",
            'default_badge' => $prod->brand?->name ?: 'স্পেশাল অফার',
            'default_video_url' => $defaultVideo,
            'default_benefits' => $defaultBenefits,
            'default_features' => [],
            'default_faqs' => $defaultFaqs,
        ]);
    }

    /**
     * Update product landing page config.
     */
    public function updateProductLanding(int $id, array $data): JsonResponse
    {
        $prod = Product::find($id);
        if (! $prod) {
            return responseError('Product not found', 404);
        }

        $benefits = $data['landing_benefits'] ?? null;
        if (is_string($benefits)) {
            $benefits = json_decode($benefits, true);
        }

        $features = $data['landing_features'] ?? null;
        if (is_string($features)) {
            $features = json_decode($features, true);
        }

        $faqs = $data['landing_faqs'] ?? ($data['faqs'] ?? null);
        if (is_string($faqs)) {
            $faqs = json_decode($faqs, true);
        }

        $prod->update([
            'landing_headline' => ! empty($data['landing_headline']) ? trim($data['landing_headline']) : null,
            'landing_subtitle' => ! empty($data['landing_subtitle']) ? trim($data['landing_subtitle']) : null,
            'landing_badge' => ! empty($data['landing_badge']) ? trim($data['landing_badge']) : null,
            'landing_video_url' => ! empty($data['landing_video_url']) ? trim($data['landing_video_url']) : null,
            'landing_benefits' => is_array($benefits) && count($benefits) > 0 ? $benefits : null,
            'landing_features' => is_array($features) && count($features) > 0 ? $features : null,
            'landing_faqs' => is_array($faqs) && count($faqs) > 0 ? $faqs : null,
        ]);

        // 1. Update or create ProductLandingConfig
        if (isset($data['landing_config']) && is_array($data['landing_config'])) {
            $cfg = $data['landing_config'];
            $howToUse = $cfg['how_to_use_steps'] ?? null;
            if (is_string($howToUse)) {
                $howToUse = json_decode($howToUse, true);
            }

            ProductLandingConfig::updateOrCreate(
                ['product_id' => $prod->id],
                [
                    'timer_enabled' => ! empty($cfg['timer_enabled']),
                    'timer_end_time' => ! empty($cfg['timer_end_time']) ? $cfg['timer_end_time'] : null,
                    'timer_title' => ! empty($cfg['timer_title']) ? trim($cfg['timer_title']) : 'আজকের বিশেষ অফার শেষ হতে বাকি',
                    'stock_scarcity_enabled' => ! empty($cfg['stock_scarcity_enabled']),
                    'stock_scarcity_threshold' => isset($cfg['stock_scarcity_threshold']) ? (int) $cfg['stock_scarcity_threshold'] : 10,
                    'stock_scarcity_text' => ! empty($cfg['stock_scarcity_text']) ? trim($cfg['stock_scarcity_text']) : 'সীমিত স্টক! দ্রুত শেষ হয়ে যাচ্ছে',
                    'whatsapp_order_enabled' => ! empty($cfg['whatsapp_order_enabled']),
                    'whatsapp_custom_number' => ! empty($cfg['whatsapp_custom_number']) ? trim($cfg['whatsapp_custom_number']) : null,
                    'whatsapp_custom_message' => ! empty($cfg['whatsapp_custom_message']) ? trim($cfg['whatsapp_custom_message']) : null,
                    'recent_sales_ticker_enabled' => ! empty($cfg['recent_sales_ticker_enabled']),
                    'delivery_timeline_inside' => ! empty($cfg['delivery_timeline_inside']) ? trim($cfg['delivery_timeline_inside']) : '২৪ থেকে ৪৮ ঘণ্টার মধ্যে ডেলিভারি',
                    'delivery_timeline_outside' => ! empty($cfg['delivery_timeline_outside']) ? trim($cfg['delivery_timeline_outside']) : '২ থেকে ৩ কার্যদিবসের মধ্যে ডেলিভারি',
                    'how_to_use_steps' => is_array($howToUse) && count($howToUse) > 0 ? $howToUse : null,
                ]
            );
        }

        // 2. Sync Tier Offers
        if (isset($data['tier_offers']) && is_array($data['tier_offers'])) {
            // Delete removed tier offers
            $incomingIds = collect($data['tier_offers'])->pluck('id')->filter()->all();
            LandingTierOffer::where('product_id', $prod->id)
                ->whereNotIn('id', $incomingIds)
                ->delete();

            foreach ($data['tier_offers'] as $index => $tier) {
                if (empty($tier['title'])) {
                    continue;
                }

                $tierData = [
                    'product_id' => $prod->id,
                    'quantity' => max(1, (int) ($tier['quantity'] ?? 1)),
                    'title' => trim($tier['title']),
                    'subtitle' => ! empty($tier['subtitle']) ? trim($tier['subtitle']) : null,
                    'discount_amount' => (float) ($tier['discount_amount'] ?? 0),
                    'discount_percentage' => (float) ($tier['discount_percentage'] ?? 0),
                    'is_free_shipping' => ! empty($tier['is_free_shipping']),
                    'gift_text' => ! empty($tier['gift_text']) ? trim($tier['gift_text']) : null,
                    'gift_product_id' => ! empty($tier['gift_product_id']) ? (int) $tier['gift_product_id'] : null,
                    'gift_variant_id' => ! empty($tier['gift_variant_id']) ? (int) $tier['gift_variant_id'] : null,
                    'badge_text' => ! empty($tier['badge_text']) ? trim($tier['badge_text']) : null,
                    'is_popular' => ! empty($tier['is_popular']),
                    'sort_order' => isset($tier['sort_order']) ? (int) $tier['sort_order'] : $index,
                    'is_active' => isset($tier['is_active']) ? (bool) $tier['is_active'] : true,
                ];

                if (! empty($tier['id'])) {
                    LandingTierOffer::where('id', $tier['id'])->where('product_id', $prod->id)->update($tierData);
                } else {
                    LandingTierOffer::create($tierData);
                }
            }
        }

        // 3. Sync shoppable landing videos
        if (isset($data['landing_videos']) && is_array($data['landing_videos'])) {
            $this->syncLandingVideos($data['landing_videos'], $prod->id, null);
        }

        return responseSuccess($prod->fresh(['landingConfig', 'tierOffers', 'landingVideos']), 'Product landing page updated successfully');
    }

    /**
     * Reset product landing page to default.
     */
    public function resetProductLanding(int $id): JsonResponse
    {
        $prod = Product::find($id);
        if (! $prod) {
            return responseError('Product not found', 404);
        }

        $prod->update([
            'landing_headline' => null,
            'landing_subtitle' => null,
            'landing_badge' => null,
            'landing_video_url' => null,
            'landing_benefits' => null,
            'landing_features' => null,
            'landing_faqs' => null,
        ]);

        ProductLandingConfig::where('product_id', $prod->id)->delete();
        LandingTierOffer::where('product_id', $prod->id)->delete();
        LandingPageVideo::where('product_id', $prod->id)->delete();

        return responseSuccess(null, 'Product landing page reset to default successfully');
    }

    /**
     * Sync landing videos for product or category.
     */
    protected function syncLandingVideos(array $videos, ?int $productId, ?int $categoryId): void
    {
        $query = LandingPageVideo::query();
        if ($productId) {
            $query->where('product_id', $productId);
        } elseif ($categoryId) {
            $query->where('category_id', $categoryId);
        } else {
            return;
        }

        $incomingIds = collect($videos)->pluck('id')->filter()->all();
        $query->whereNotIn('id', $incomingIds)->delete();

        foreach ($videos as $index => $item) {
            if (empty($item['video_url'])) {
                continue;
            }

            $videoUrl = trim($item['video_url']);
            $videoId = LandingPageVideo::extractYouTubeId($videoUrl);

            $videoData = [
                'product_id' => $productId,
                'category_id' => $categoryId,
                'video_url' => $videoUrl,
                'video_id' => $videoId,
                'title' => ! empty($item['title']) ? trim($item['title']) : null,
                'badge_text' => ! empty($item['badge_text']) ? trim($item['badge_text']) : null,
                'featured_product_id' => ! empty($item['featured_product_id']) ? (int) $item['featured_product_id'] : null,
                'sort_order' => isset($item['sort_order']) ? (int) $item['sort_order'] : $index,
                'is_active' => isset($item['is_active']) ? (bool) $item['is_active'] : true,
            ];

            if (! empty($item['id'])) {
                LandingPageVideo::where('id', $item['id'])->update($videoData);
            } else {
                LandingPageVideo::create($videoData);
            }
        }
    }
}
