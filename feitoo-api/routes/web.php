<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\QuotationDraftController;
use App\Http\Controllers\QuotationGenerationController;
use App\Http\Controllers\QuotationOrderController;
use App\Http\Middleware\QuotationDraftAccountContext;
use App\Http\Middleware\RequireProcessingOwner;
use Illuminate\Support\Facades\Route;

// Session and CSRF are mandatory, including requests without Origin/Referer.
Route::prefix('api/quotations/drafts')->middleware(['auth:sanctum', QuotationDraftAccountContext::class, 'throttle:quotation-drafts'])->group(function () {
    Route::get('/', [QuotationDraftController::class, 'index']);
    Route::post('/', [QuotationDraftController::class, 'store']);
    Route::get('/{uuid}', [QuotationDraftController::class, 'show'])->whereUuid('uuid');
    Route::patch('/{uuid}', [QuotationDraftController::class, 'update'])->whereUuid('uuid');
    Route::delete('/{uuid}', [QuotationDraftController::class, 'destroy'])->whereUuid('uuid');
    Route::put('/{uuid}/logo', [QuotationDraftController::class, 'putLogo'])->whereUuid('uuid');
    Route::get('/{uuid}/logo', [QuotationDraftController::class, 'logo'])->whereUuid('uuid');
    Route::delete('/{uuid}/logo', [QuotationDraftController::class, 'deleteLogo'])->whereUuid('uuid');
    Route::post('/{uuid}/generations', [QuotationGenerationController::class, 'store'])->whereUuid('uuid')->middleware('throttle:quotation-generate');
});

Route::prefix('api/quotations')->middleware(['auth:sanctum', QuotationDraftAccountContext::class])->group(function () {
    Route::post('/credit-orders', [QuotationOrderController::class, 'store'])->middleware('throttle:quotation-generate');
    Route::get('/credit-orders', [QuotationOrderController::class, 'index'])->middleware('throttle:quotation-drafts');
    Route::get('/credit-orders/{uuid}', [QuotationOrderController::class, 'show'])->whereUuid('uuid')->middleware('throttle:quotation-generate');
    Route::get('/entitlements', [QuotationGenerationController::class, 'entitlements'])->middleware('throttle:quotation-drafts');
    Route::post('/generations/{uuid}/retry', [QuotationGenerationController::class, 'retry'])->whereUuid('uuid')->middleware('throttle:quotation-generate');
});

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
