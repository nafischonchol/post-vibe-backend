<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AdjustmentController;
use App\Http\Controllers\Admin\AiSettingController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CourierSettingController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SearchLogController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\SeoAiGeneratorController;
use App\Http\Controllers\Admin\SeoSettingController;
use App\Http\Controllers\Admin\ShippingSettingController;
use App\Http\Controllers\Admin\StoreSetupController;
use App\Http\Controllers\Admin\CustomerPaymentController;
use App\Http\Controllers\Admin\SupplierPaymentController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\BlogPostController;
use App\Http\Controllers\Admin\BlogCommentController;
use App\Http\Controllers\Admin\BlogSourceController;
use App\Http\Controllers\Admin\ComboController;
use App\Http\Controllers\Admin\CartController;
use App\Http\Controllers\Admin\WishlistController;
use App\Http\Controllers\Admin\LandingPageController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'admin.check'])->group(function () {
    Route::get('profile', [ProfileController::class, 'show']);
    Route::post('profile', [ProfileController::class, 'update']);

    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{id}', [UserController::class, 'show']);
    Route::post('users', [UserController::class, 'store']);
    Route::post('users/{id}', [UserController::class, 'update']);
    Route::put('users/{id}', [UserController::class, 'update']);
    Route::delete('users/{id}', [UserController::class, 'destroy']);

    Route::patch('categories/{category}/toggle-header-menu', [CategoryController::class, 'toggleHeaderMenu']);
    Route::post('categories/{category}/toggle-header-menu', [CategoryController::class, 'toggleHeaderMenu']);
    Route::apiResource('categories', CategoryController::class)->except('destroy');
    Route::get('clients/lookup', [ClientController::class, 'lookup']);
    Route::get('clients/{client}/transactions', [ClientController::class, 'clientTransactions']);
    Route::post('clients/{client}/status', [ClientController::class, 'updateStatus']);
    Route::apiResource('clients', ClientController::class)->except('destroy');
    Route::apiResource('brands', BrandController::class)->except('destroy');
    Route::apiResource('attributes', AttributeController::class)->except('destroy');
    Route::apiResource('units', UnitController::class)->except('destroy');
    Route::get('banner-types', [BannerController::class, 'types']);
    Route::post('banners/{banner}/status', [BannerController::class, 'updateStatus']);
    Route::apiResource('banners', BannerController::class)->except('destroy');
    Route::get('accounts/transactions', [AccountController::class, 'transactions']);
    Route::apiResource('accounts', AccountController::class)->except('destroy');
    Route::post('accounts/{account}/add-funds', [AccountController::class, 'addFunds']);
    Route::post('accounts/{account}/settle-courier-payout', [AccountController::class, 'settleCourierPayout']);
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/check/barcode', [ProductController::class, 'checkByBarcode']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('products/{product}/edit-payload', [ProductController::class, 'editPayload']);
    Route::post('products/{product}', [ProductController::class, 'update']);

    Route::get('combos/{combo}/edit-payload', [ComboController::class, 'editPayload']);
    Route::post('combos/{combo}/status', [ComboController::class, 'toggleStatus']);
    Route::post('combos/{combo}', [ComboController::class, 'update']);
    Route::apiResource('combos', ComboController::class);

    Route::post('adjustments', [AdjustmentController::class, 'store']);
    Route::get('adjustments', [AdjustmentController::class, 'index']);

    Route::get('purchases', [PurchaseController::class, 'index']);
    Route::post('purchases', [PurchaseController::class, 'store']);
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show']);
    Route::post('purchases/{purchase}/receive-items', [PurchaseController::class, 'receiveItems']);

    Route::get('suppliers/{client}/due-invoices', [SupplierPaymentController::class, 'getDueInvoices']);
    Route::post('suppliers/{client}/pay-due', [SupplierPaymentController::class, 'payDue']);
    Route::get('customers/{client}/due-orders', [CustomerPaymentController::class, 'getDueOrders']);
    Route::post('customers/{client}/collect-due', [CustomerPaymentController::class, 'collectDue']);

    Route::get('products/{product}/stock-movements', [ProductController::class, 'stockMovements']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/lookup', [OrderController::class, 'lookup']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::put('orders/{order}', [OrderController::class, 'update']);
    Route::put('orders/{order}/items', [OrderController::class, 'updateItems']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/{order}/status', [OrderController::class, 'updateStatus']);

    Route::get('coupons/generate-code', [CouponController::class, 'generateUniqueCode']);
    Route::post('coupons/{coupon}/status', [CouponController::class, 'updateStatus']);
    Route::get('coupons/{coupon}/usages', [CouponController::class, 'usages']);
    Route::apiResource('coupons', CouponController::class);

    Route::get('courier-settings', [CourierSettingController::class, 'index']);
    Route::post('courier-settings', [CourierSettingController::class, 'updateOrCreate']);
    Route::get('courier/check-fraud', [CourierSettingController::class, 'checkFraud']);

    Route::get('seo-settings', [SeoSettingController::class, 'show']);
    Route::post('seo-settings', [SeoSettingController::class, 'updateOrCreate']);
    Route::get('seo-settings/404-logs', [SeoSettingController::class, 'logs']);
    Route::delete('seo-settings/404-logs', [SeoSettingController::class, 'clearLogs']);

    Route::get('search-logs/summary', [SearchLogController::class, 'summary']);
    Route::get('search-logs/trending', [SearchLogController::class, 'trending']);
    Route::get('search-logs/not-found', [SearchLogController::class, 'notFound']);
    Route::get('search-logs/typos', [SearchLogController::class, 'typos']);
    Route::get('search-logs', [SearchLogController::class, 'index']);
    Route::delete('search-logs/clear', [SearchLogController::class, 'clear']);

    Route::get('business-profile', [StoreSetupController::class, 'show']);
    Route::post('business-profile', [StoreSetupController::class, 'update']);
    Route::delete('business-profile/logo', [StoreSetupController::class, 'deleteLogo']);

    Route::get('shipping-settings', [ShippingSettingController::class, 'show']);
    Route::post('shipping-settings', [ShippingSettingController::class, 'update']);

    Route::get('ai-settings', [AiSettingController::class, 'show']);
    Route::post('ai-settings', [AiSettingController::class, 'update']);
    Route::post('ai-settings/test', [AiSettingController::class, 'testConnection']);
    Route::post('seo-generator/generate', [SeoAiGeneratorController::class, 'generate']);

    Route::get('locations/divisions', [LocationController::class, 'divisions']);
    Route::get('locations/districts/{divisionId}', [LocationController::class, 'districts']);
    Route::get('locations/upazilas/{districtId}', [LocationController::class, 'upazilas']);
    Route::get('locations/areas/{upazilaId}', [LocationController::class, 'areas']);

    // Blogs Management
    Route::post('blog-categories/{blog_category}/toggle-status', [BlogCategoryController::class, 'toggleStatus']);
    Route::apiResource('blog-categories', BlogCategoryController::class);

    Route::post('blog-posts/generate-ai', [BlogPostController::class, 'generateAi']);
    Route::post('blog-posts/upload-image', [BlogPostController::class, 'uploadImage']);
    Route::post('blog-posts/{blog_post}/toggle-status', [BlogPostController::class, 'toggleStatus']);
    Route::post('blog-posts/{blog_post}/toggle-featured', [BlogPostController::class, 'toggleFeatured']);
    Route::apiResource('blog-posts', BlogPostController::class);

    Route::get('blog-comments', [BlogCommentController::class, 'index']);
    Route::post('blog-comments/{comment}/status', [BlogCommentController::class, 'updateStatus']);
    Route::post('blog-comments/{comment}/reply', [BlogCommentController::class, 'adminReply']);
    Route::delete('blog-comments/{comment}', [BlogCommentController::class, 'destroy']);

    // Blog Auto-Sources & Curation
    Route::get('blog-sources/settings', [BlogSourceController::class, 'getSettings']);
    Route::post('blog-sources/settings', [BlogSourceController::class, 'updateSettings']);
    Route::get('blog-sources/logs', [BlogSourceController::class, 'logs']);
    Route::post('blog-sources/curate', [BlogSourceController::class, 'triggerCuration']);
    Route::post('blog-sources/{blog_source}/curate', [BlogSourceController::class, 'triggerCuration']);
    Route::post('blog-sources/{blog_source}/toggle-status', [BlogSourceController::class, 'toggleStatus']);
    Route::apiResource('blog-sources', BlogSourceController::class);

    // Cart Management
    Route::get('carts', [CartController::class, 'index']);
    Route::get('carts/demand', [CartController::class, 'demand']);
    Route::delete('carts/{id}', [CartController::class, 'destroy']);

    // Wishlist Management
    Route::get('wishlists', [WishlistController::class, 'index']);
    Route::get('wishlists/demand', [WishlistController::class, 'demand']);
    Route::delete('wishlists/{id}', [WishlistController::class, 'destroy']);

    // Live Chat & AI Assistant
    Route::prefix('live-chat')->group(function () {
        Route::get('conversations', [\App\Http\Controllers\Admin\LiveChatController::class, 'index']);
        Route::get('conversations/{id}', [\App\Http\Controllers\Admin\LiveChatController::class, 'show']);
        Route::post('conversations/{id}/reply', [\App\Http\Controllers\Admin\LiveChatController::class, 'reply']);
        Route::patch('conversations/{id}/mode', [\App\Http\Controllers\Admin\LiveChatController::class, 'updateMode']);
        Route::get('settings', [\App\Http\Controllers\Admin\LiveChatController::class, 'getSettings']);
        Route::put('settings', [\App\Http\Controllers\Admin\LiveChatController::class, 'updateSettings']);
        Route::get('models', [\App\Http\Controllers\Admin\LiveChatController::class, 'getModels']);
        Route::get('channel-settings', [\App\Http\Controllers\Admin\ChatChannelSettingController::class, 'index']);
        Route::put('channel-settings/{channel}', [\App\Http\Controllers\Admin\ChatChannelSettingController::class, 'update']);
    });

    // Landing Pages Management
    Route::prefix('landing-pages')->group(function () {
        // Categories
        Route::get('categories', [LandingPageController::class, 'categories']);
        Route::get('categories/{id}', [LandingPageController::class, 'categoryDetail']);
        Route::post('categories/{id}', [LandingPageController::class, 'updateCategory']);
        Route::put('categories/{id}', [LandingPageController::class, 'updateCategory']);
        Route::post('categories/{id}/reset', [LandingPageController::class, 'resetCategory']);

        // Products
        Route::get('products', [LandingPageController::class, 'products']);
        Route::get('products/{id}', [LandingPageController::class, 'productDetail']);
        Route::post('products/{id}', [LandingPageController::class, 'updateProduct']);
        Route::put('products/{id}', [LandingPageController::class, 'updateProduct']);
        Route::post('products/{id}/reset', [LandingPageController::class, 'resetProduct']);
    });
});
