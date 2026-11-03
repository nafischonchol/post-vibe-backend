<?php

use App\Http\Controllers\Tenant\AuthController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\MetadataController;
use App\Http\Controllers\Tenant\PlatformAccountController;
use App\Http\Controllers\Tenant\PublishingController;
use App\Http\Controllers\Tenant\QuotaController;
use App\Http\Controllers\Tenant\SourceController;
use App\Http\Controllers\Tenant\VideoController;
use Illuminate\Support\Facades\Route;

// Protected Tenant Routes (Guarded by auth:tenant & tenant.check)
Route::middleware(['auth:tenant', 'tenant.check'])->group(function () {
    // 1. Auth & Profile
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // 2. Dashboard
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 3. Video Sources
    Route::apiResource('sources', SourceController::class);

    // 4. Video Management
    Route::get('videos', [VideoController::class, 'index'])->name('videos.index');
    Route::get('videos/{id}', [VideoController::class, 'show'])->name('videos.show');
    Route::post('videos/{id}/reprocess', [VideoController::class, 'reprocess'])->name('videos.reprocess');

    // 5. AI Metadata Management
    Route::get('videos/{id}/metadata', [MetadataController::class, 'show'])->name('metadata.show');
    Route::put('videos/{id}/metadata/{platform}', [MetadataController::class, 'update'])->name('metadata.update');
    Route::post('videos/{id}/metadata/regenerate', [MetadataController::class, 'regenerate'])->name('metadata.regenerate');

    // 6. Connected Platform Accounts
    Route::get('platform-accounts', [PlatformAccountController::class, 'index'])->name('platform-accounts.index');
    Route::post('platform-accounts', [PlatformAccountController::class, 'store'])->name('platform-accounts.store');
    Route::delete('platform-accounts/{id}', [PlatformAccountController::class, 'destroy'])->name('platform-accounts.destroy');

    // 7. Publishing & Batches
    Route::get('publishing/batches', [PublishingController::class, 'index'])->name('publishing.batches.index');
    Route::post('publishing/batches', [PublishingController::class, 'store'])->name('publishing.batches.store');
    Route::get('publishing/batches/{id}', [PublishingController::class, 'show'])->name('publishing.batches.show');
    Route::post('publishing/batches/{id}/cancel', [PublishingController::class, 'cancel'])->name('publishing.batches.cancel');

    // 8. Quotas & Usage
    Route::get('quota', [QuotaController::class, 'show'])->name('quota.show');
    Route::get('quota/history', [QuotaController::class, 'history'])->name('quota.history');
});
