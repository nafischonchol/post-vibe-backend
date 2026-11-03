<?php

use App\Http\Controllers\Admin\AiSettingController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlatformApplicationController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SystemSettingController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Public Admin Auth
Route::post('login', [AuthController::class, 'login'])->name('login');

// Protected Admin Routes (Guarded by auth:admin & admin.check)
Route::middleware(['auth:admin', 'admin.check'])->group(function () {
    // 1. Dashboard Overview
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 2. Profile
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('profile', [ProfileController::class, 'update'])->name('profile.update');

    // 3. Admin Users
    Route::apiResource('users', UserController::class);

    // 4. Tenant Management
    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{id}', [TenantController::class, 'show'])->name('tenants.show');
    Route::put('tenants/{id}', [TenantController::class, 'update'])->name('tenants.update');
    Route::post('tenants/{id}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('tenants/{id}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
    Route::post('tenants/{id}/quota-override', [TenantController::class, 'setQuotaOverride'])->name('tenants.quota-override');

    // 5. Subscription Plans
    Route::apiResource('plans', PlanController::class);

    // 6. Global System Settings
    Route::get('settings', [SystemSettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [SystemSettingController::class, 'update'])->name('settings.update');

    // 7. Platform Applications (YouTube, TikTok, Facebook OAuth credentials)
    Route::get('platform-applications', [PlatformApplicationController::class, 'index'])->name('platform-applications.index');
    Route::put('platform-applications/{platform}', [PlatformApplicationController::class, 'update'])->name('platform-applications.update');

    // 8. AI Gateway Configuration
    Route::get('ai-settings', [AiSettingController::class, 'show'])->name('ai-settings.show');
    Route::post('ai-settings', [AiSettingController::class, 'update'])->name('ai-settings.update');
    Route::post('ai-settings/test', [AiSettingController::class, 'testConnection'])->name('ai-settings.test');
});
