<?php

use App\Http\Controllers\Api\OAuthController;
use Illuminate\Support\Facades\Route;

// Webhook and Social Platform Callback Endpoints
Route::get('ping', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('webhooks.oauth.callback');
