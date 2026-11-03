<?php

use App\Http\Controllers\Admin\SeoSettingController;
use App\Http\Controllers\Admin\ShippingSettingController;
use App\Http\Controllers\Admin\StoreSetupController;
use App\Http\Controllers\Customer\AnalyticsTrackingController;
use App\Http\Controllers\Customer\AttributeController;
use App\Http\Controllers\Customer\AuthController;
use App\Http\Controllers\Customer\BannerController;
use App\Http\Controllers\Customer\BrandController;
use App\Http\Controllers\Customer\CartController;
use App\Http\Controllers\Customer\CategoryController;
use App\Http\Controllers\Customer\CouponController;
use App\Http\Controllers\Customer\DistrictController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Customer\ProductController;
use App\Http\Controllers\Customer\QuestionController;
use App\Http\Controllers\Customer\ReviewController;
use App\Http\Controllers\Customer\SearchLogController;
use App\Http\Controllers\Customer\SitemapController;
use App\Http\Controllers\Customer\VisitorController;
use App\Http\Controllers\Customer\WishlistController;
use App\Http\Controllers\Customer\PublicBlogController;
use App\Http\Controllers\Customer\PublicComboController;
use App\Http\Middleware\OptionalSanctumAuth;
use Illuminate\Support\Facades\Route;

// Customer Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('send-otp', [AuthController::class, 'sendOtp']);
    Route::post('login-otp', [AuthController::class, 'loginOtp']);
    Route::post('register-otp', [AuthController::class, 'registerOtp']);
});
Route::post('login', [AuthController::class, 'login']);

Route::middleware(OptionalSanctumAuth::class)->group(function () {
    Route::prefix('cart')->group(function () {
        Route::get('/', [CartController::class, 'index']);
        Route::post('/', [CartController::class, 'store']);
        Route::put('/{id}', [CartController::class, 'update']);
        Route::delete('/{id}', [CartController::class, 'destroy']);
        Route::delete('/', [CartController::class, 'clear']);
    });

    Route::post('orders', [OrderController::class, 'store']);
    Route::post('coupons/validate', [CouponController::class, 'validateCoupon']);
    Route::post('analytics/track-event', [AnalyticsTrackingController::class, 'trackEvent']);
    Route::get('visitor/session', [VisitorController::class, 'session']);

    Route::get('wishlist', [WishlistController::class, 'index']);
    Route::post('wishlist/toggle', [WishlistController::class, 'toggle']);
    Route::get('banners/{type}', [BannerController::class, 'getByType']);
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('popular-brands', [BrandController::class, 'popularBrands']);
    Route::get('popular-categories', [CategoryController::class, 'popularCategories']);
    Route::get('menu-categories', [CategoryController::class, 'menuCategories']);
    Route::get('category-landing/{slug}', [CategoryController::class, 'categoryLanding']);
    Route::get('popular-products', [ProductController::class, 'popularProducts']);
    Route::get('best-selling-products', [ProductController::class, 'bestSellingProducts']);
    Route::get('new-arrival-products', [ProductController::class, 'newArrivalProducts']);
    Route::get('brand/{brand_id}/products', [ProductController::class, 'brandProducts']);
    Route::get('category/{category_id}/products', [ProductController::class, 'categoryProducts']);
    Route::get('product/{slug_url}', [ProductController::class, 'show']);
    Route::get('product/{slug_url}/reviews', [ReviewController::class, 'index']);
    Route::get('product/{slug_url}/review-eligibility', [ReviewController::class, 'checkEligibility']);
    Route::post('product/{slug_url}/reviews', [ReviewController::class, 'store']);
    Route::post('reviews/{id}/helpful', [ReviewController::class, 'voteHelpful']);
    Route::get('product/{slug_url}/questions', [QuestionController::class, 'index']);
    Route::post('product/{slug_url}/questions', [QuestionController::class, 'store']);
    Route::post('questions/{id}/like', [QuestionController::class, 'like']);
    Route::get('business-profile', [StoreSetupController::class, 'show']);
    Route::get('shipping-settings', [ShippingSettingController::class, 'showPublic']);
    Route::get('seo-settings', [SeoSettingController::class, 'showPublic']);
    Route::get('districts', [DistrictController::class, 'index']);
    Route::get('products/filter', [ProductController::class, 'filterProducts']);
    Route::get('products/search-suggestions', [ProductController::class, 'searchSuggestions']);
    Route::post('search-logs/click', [SearchLogController::class, 'trackClick']);
    Route::get('search/trending', [SearchLogController::class, 'trendingKeywords']);
    Route::get('filterable-data', [ProductController::class, 'getFilterableData']);
    Route::get('sitemap-data', [SitemapController::class, 'index']);
    Route::get('sitemap-data/products', [SitemapController::class, 'products']);
    Route::get('sitemap-data/categories', [SitemapController::class, 'categories']);
    Route::get('sitemap-data/brands', [SitemapController::class, 'brands']);

    Route::get('attributes/{slug}/values', [AttributeController::class, 'getAttributeValues']);

    // Blogs Public Routes
    Route::get('blogs', [PublicBlogController::class, 'index']);
    Route::get('blogs/categories', [PublicBlogController::class, 'categories']);
    Route::get('blogs/featured', [PublicBlogController::class, 'featured']);
    Route::get('blogs/recent', [PublicBlogController::class, 'recent']);
    Route::get('blogs/{slug}', [PublicBlogController::class, 'show']);
    Route::get('blogs/{slug}/comments', [PublicBlogController::class, 'comments']);
    Route::post('blogs/{slug}/comments', [PublicBlogController::class, 'storeComment']);

    // Combos Public Routes
    Route::get('combos', [PublicComboController::class, 'index']);
    Route::get('combos/{slug_url}', [PublicComboController::class, 'show']);
});
