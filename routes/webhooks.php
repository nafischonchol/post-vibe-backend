<?php

use Illuminate\Support\Facades\Route;

// Webhook endpoints placeholder for Automated Video Syndication SaaS (Section 5.7 / Social platform callbacks)
Route::get('ping', function () {
    return response()->json(['status' => 'ok']);
});
