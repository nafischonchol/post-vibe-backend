<?php

namespace App\Services\Customer;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;

class CategoryLandingService
{
    /**
     * Fetch all data needed for the category landing page.
     */
    public function getCategoryLandingData(string $slug): JsonResponse
    {
        $category = Category::with([
            'landingConfig',
            'landingVideos' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order', 'asc'),
            'landingVideos.featuredProduct:id,name,slug,image,brand_id',
            'landingVideos.featuredProduct.variants:id,product_id,price,discount_price',
            'landingVideos.featuredProduct.brand:id,name',
        ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $category) {
            return responseError('Category not found', 404);
        }

        // 1. Get descendant category IDs including self
        $categoryIds = $category->descendantsAndSelf()->pluck('id');

        // 2. Query up to 10 active products in this category
        $products = Product::where('status', 'active')
            ->whereIn('category_id', $categoryIds)
            ->with([
                'variants' => function ($q) {
                    $q->active()->select([
                        'id',
                        'product_id',
                        'sku',
                        'price',
                        'discount_price',
                        'stock',
                    ]);
                },
                'reviews' => function ($q) {
                    $q->where('status', 'approved')->select(['id', 'product_id', 'rating']);
                },
                'category:id,name,slug',
                'brand:id,name,slug',
            ])
            ->select([
                'id',
                'name',
                'slug',
                'short_description',
                'description',
                'image',
                'blur_image',
                'category_id',
                'brand_id',
                'youtube_video_urls',
                'status',
                'created_at',
            ])
            ->latest('id')
            ->limit(10)
            ->get();

        // 3. Map products into a clean, predictable structure
        $mappedProducts = $products->map(function ($product) {
            $variants = $product->variants;
            $hasVariants = $variants->count() > 1;
            if ($variants->count() === 1) {
                $sku = $variants->first()->sku ?? '';
                if ($sku && ! str_contains($sku, '-default-')) {
                    $hasVariants = true;
                }
            }

            $minPrice = $variants->isNotEmpty() ? (float) $variants->min('price') : 0;
            $minDiscountPrice = $variants->isNotEmpty() ? (float) $variants->min('discount_price') : 0;
            $totalStock = $variants->isNotEmpty() ? (int) $variants->sum('stock') : 0;

            $price = ($minDiscountPrice > 0 && $minDiscountPrice < $minPrice) ? $minDiscountPrice : $minPrice;
            $originalPrice = ($minDiscountPrice > 0 && $minDiscountPrice < $minPrice) ? $minPrice : null;
            $discountPercent = ($originalPrice && $originalPrice > $price)
                ? (int) round((($originalPrice - $price) / $originalPrice) * 100)
                : 0;

            $approvedReviews = $product->reviews;
            $reviewsCount = $approvedReviews->count();
            $avgRating = $reviewsCount > 0 ? round((float) $approvedReviews->avg('rating'), 1) : 5.0;

            $youtubeUrls = is_array($product->youtube_video_urls)
                ? array_filter($product->youtube_video_urls)
                : [];

            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'slug_url' => $product->slug_url,
                'thumbnail' => Product::getImageUrl($product->image),
                'blur_image' => $product->blur_image,
                'price' => $price,
                'original_price' => $originalPrice,
                'discount_percent' => $discountPercent,
                'has_variants' => $hasVariants,
                'default_variant_id' => $variants->first()?->id,
                'total_stock' => $totalStock,
                'in_stock' => $totalStock > 0,
                'short_description' => $product->short_description ?: $product->description,
                'rating' => $avgRating,
                'reviews_count' => $reviewsCount,
                'category_name' => $product->category?->name,
                'brand_name' => $product->brand?->name,
                'youtube_urls' => array_values($youtubeUrls),
            ];
        });

        // 4. Resolve featured YouTube video URL
        $featuredVideoUrl = $category->landing_video_url;
        if (! $featuredVideoUrl) {
            foreach ($mappedProducts as $p) {
                if (! empty($p['youtube_urls'])) {
                    $featuredVideoUrl = $p['youtube_urls'][0];
                    break;
                }
            }
        }

        // 5. Default Benefits if not customized
        $defaultBenefits = [
            [
                'title' => 'সারা দেশে দ্রুত ডেলিভারি',
                'description' => 'ঢাকা ও ঢাকার বাইরে ক্যাশ অন ডেলিভারি সুবিধা',
                'desc' => 'ঢাকা ও ঢাকার বাইরে ক্যাশ অন ডেলিভারি সুবিধা',
                'icon' => 'Truck',
            ],
            [
                'title' => '১০০% অরিজিনাল পণ্য',
                'description' => 'সরাসরি ব্র্যান্ড ও অথেনটিক সোর্স থেকে আমদানিকৃত',
                'desc' => 'সরাসরি ব্র্যান্ড ও অথেনটিক সোর্স থেকে আমদানিকৃত',
                'icon' => 'ShieldCheck',
            ],
            [
                'title' => 'ক্যাশ অন ডেলিভারি',
                'description' => 'পণ্য হাতে পেয়ে চেক করে পেমেন্ট করার সুবিধা',
                'desc' => 'পণ্য হাতে পেয়ে চেক করে পেমেন্ট করার সুবিধা',
                'icon' => 'PackageCheck',
            ],
            [
                'title' => 'সহজ রিটার্ন পলিসি',
                'description' => 'কোনো সমস্যা হলে দ্রুত রিপ্লেসমেন্ট ও কাস্টমার সাপোর্ট',
                'desc' => 'কোনো সমস্যা হলে দ্রুত রিপ্লেসমেন্ট ও কাস্টমার সাপোর্ট',
                'icon' => 'RotateCcw',
            ],
        ];

        // 6. Default How-it-works Steps if not customized
        $defaultSteps = [
            [
                'step' => '১',
                'title' => 'পণ্য সিলেক্ট করুন',
                'description' => 'আপনার পছন্দের প্রোডাক্ট বেছে নিয়ে অর্ডার ফর্মে যান',
                'desc' => 'আপনার পছন্দের প্রোডাক্ট বেছে নিয়ে অর্ডার ফর্মে যান',
            ],
            [
                'step' => '২',
                'title' => 'অর্ডার কনফার্ম করুন',
                'description' => 'নাম, মোবাইল নাম্বার ও ঠিকানা দিয়ে সহজে অর্ডার সম্পন্ন করুন',
                'desc' => 'নাম, মোবাইল নাম্বার ও ঠিকানা দিয়ে সহজে অর্ডার সম্পন্ন করুন',
            ],
            [
                'step' => '৩',
                'title' => 'পণ্য হাতে বুঝে নিন',
                'description' => 'দ্রুততম সময়ে ডেলিভারি পেয়ে চেক করে মূল্য পরিশোধ করুন',
                'desc' => 'দ্রুততম সময়ে ডেলিভারি পেয়ে চেক করে মূল্য পরিশোধ করুন',
            ],
        ];

        // 7. Default FAQs if not customized
        $defaultFaqs = [
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

        $faqs = (! empty($category->faqs) && is_array($category->faqs) && count($category->faqs) > 0)
            ? $category->faqs
            : ((! empty($category->faqs_bn) && is_array($category->faqs_bn) && count($category->faqs_bn) > 0) ? $category->faqs_bn : $defaultFaqs);

        // 8. Fetch approved customer reviews across products in this category for social proof
        $recentReviews = ProductReview::whereIn('product_id', $products->pluck('id'))
            ->where('status', 'approved')
            ->with(['client:id,name', 'product:id,name,image'])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(function ($rev) {
                return [
                    'id' => $rev->id,
                    'client_name' => $rev->client?->name ?: 'সম্মানিত গ্রাহক',
                    'rating' => (int) $rev->rating,
                    'title' => $rev->title,
                    'content' => $rev->content,
                    'date' => $rev->created_at?->diffForHumans() ?: 'সম্প্রতি',
                    'product_name' => $rev->product?->name,
                ];
            });

        return responseSuccess([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'headline' => $category->landing_headline ?: "সেরা মানের {$category->name} কালেকশন — বিশেষ ছাড়ে আজই অর্ডার করুন",
                'subtitle' => $category->landing_subtitle ?: "আমাদের প্রিমিয়াম কোয়ালিটি কালেকশন থেকে আপনার পছন্দের প্রোডাক্ট বেছে নিন। দ্রুততম ডেলিভারি ও ১০০% অরিজিনাল পণ্যের নিশ্চয়তা।",
                'badge' => $category->landing_badge ?: "প্রিমিয়াম কোয়ালিটি গ্যারান্টি",
                'icon' => $category->icon ? Product::getImageUrl($category->icon) : null,
                'meta_image' => $category->meta_image ? Product::getImageUrl($category->meta_image) : null,
                'meta_title' => $category->meta_title ?: "{$category->name} - বিশেষ ডিসকাউন্ট অফার | Mohima",
                'meta_description' => $category->meta_description ?: "কিনুন ১০০% অরিজিনাল {$category->name} পণ্য সেরা মূল্যে মোহিমা থেকে। সারা দেশে ক্যাশ অন ডেলিভারি।",
                'meta_keywords' => $category->meta_keyword,
                'seo_content' => $category->seo_bottom_content_bn ?: $category->seo_bottom_content,
                'benefits' => ! empty($category->landing_benefits) ? $category->landing_benefits : $defaultBenefits,
                'steps' => ! empty($category->landing_steps) ? $category->landing_steps : $defaultSteps,
                'faqs' => $faqs,
                'featured_video_url' => $featuredVideoUrl,
                'landing_config' => $category->landingConfig,
                'landing_videos' => $category->landingVideos->map(function ($lv) {
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
                }),
            ],
            'products' => $mappedProducts,
            'reviews' => $recentReviews,
        ]);
    }
}
