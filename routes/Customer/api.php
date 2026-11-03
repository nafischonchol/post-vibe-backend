<?php

use App\Http\Controllers\Customer\ClientAddressController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\Customer\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'client.check'])->group(function () {
    Route::post('wishlist/merge', [WishlistController::class, 'merge']);
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'updateProfile']);
        Route::post('/', [ProfileController::class, 'updateProfile']); // For multipart avatar uploads
        Route::post('/password/send-otp', [ProfileController::class, 'sendPasswordOtp']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        Route::post('/password', [ProfileController::class, 'updatePassword']);
    });

    Route::prefix('addresses')->group(function () {
        Route::get('/', [ClientAddressController::class, 'index']);
        Route::post('/', [ClientAddressController::class, 'store']);
        Route::put('/{id}', [ClientAddressController::class, 'update']);
        Route::delete('/{id}', [ClientAddressController::class, 'destroy']);
        Route::put('/{id}/set-default', [ClientAddressController::class, 'setDefault']);
    });

    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/{id}', [OrderController::class, 'show']);
    });

    Route::prefix('chat')->group(function () {
        Route::get('/conversation', [\App\Http\Controllers\Customer\ChatController::class, 'getConversation']);
        Route::get('/messages', [\App\Http\Controllers\Customer\ChatController::class, 'getMessages']);
        Route::post('/send', [\App\Http\Controllers\Customer\ChatController::class, 'sendMessage'])->middleware('throttle:30,1');
        Route::post('/identify-image', [\App\Http\Controllers\Customer\ChatController::class, 'identifyImage'])->middleware('throttle:20,1');
    });
});
