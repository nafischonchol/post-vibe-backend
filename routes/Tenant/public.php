<?php

use App\Http\Controllers\Tenant\AuthController;
use Illuminate\Support\Facades\Route;

// Public Tenant Authentication Routes
Route::post('login', [AuthController::class, 'login'])->name('login');
