<?php

use App\Http\Controllers\Api\MetaWebhookController;
use Illuminate\Support\Facades\Route;

// Facebook Messenger Webhook
Route::get('facebook', [MetaWebhookController::class, 'verifyFacebook'])->name('facebook.verify');
Route::post('facebook', [MetaWebhookController::class, 'handleFacebook'])->name('facebook.handle');

// WhatsApp Business Cloud API Webhook
Route::get('whatsapp', [MetaWebhookController::class, 'verifyWhatsApp'])->name('whatsapp.verify');
Route::post('whatsapp', [MetaWebhookController::class, 'handleWhatsApp'])->name('whatsapp.handle');
