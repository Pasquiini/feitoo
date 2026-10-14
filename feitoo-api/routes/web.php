<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Middleware\RequireProcessingOwner;
use Illuminate\Support\Facades\Route;

// Web middleware ensures session + CSRF even when Origin/Referer are absent.
Route::prefix('api/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/google/start', [GoogleAuthController::class, 'start'])->middleware('throttle:auth');
    Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:auth')->name('auth.google.callback');
});

Route::get('/', function () {
    return view('welcome');
});

// Session + CSRF apply even when Origin/Referer are missing.
Route::prefix('api/account')->middleware(['auth:sanctum', 'throttle:auth'])->group(function () {
    Route::patch('/profile', [AccountController::class, 'profile']);
    Route::patch('/password', [AccountController::class, 'password']);
});

Route::prefix('api/processings/{processing}')->whereUuid('processing')
    ->middleware(['auth:sanctum', 'throttle:auth', RequireProcessingOwner::class])->group(function () {
        Route::post('/purchase', [BillingController::class, 'purchase']);
        Route::post('/purchase/cancel', [BillingController::class, 'cancel']);
    });
