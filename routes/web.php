<?php

use App\Http\Controllers\Api\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public Social Media OAuth Callback Route
Route::get('oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
