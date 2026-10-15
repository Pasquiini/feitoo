<?php

use App\Enums\ProcessingTool;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ProcessingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\XmlAnalysisController;
use App\Http\Middleware\RequireProcessingOwner;
use App\Services\PurchaseService;
use App\Services\StripeCheckoutGateway;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::get('/tools/{tool}/config', function (string $tool) {
    return response()->json(['data' => [
        'max_files' => config('feitoo.uploads.max_files'),
        'max_request_size_kb' => config('feitoo.uploads.max_request_size_kb'),
        'max_processing_files' => config('feitoo.uploads.max_processing_files'),
        'max_processing_items' => config('feitoo.uploads.max_processing_items'),
        'max_total_size_kb' => config('feitoo.uploads.max_total_size_kb'),
        'max_file_size_kb' => config('feitoo.uploads.max_file_size_kb'),
        'ttl_minutes' => config('feitoo.processing.ttl_minutes'),
        'free' => ['per_processing' => config('monetization.free.per_processing'), 'daily' => config('monetization.free.daily')],
        'packages' => in_array($tool, [ProcessingTool::XmlToPdf->value, ProcessingTool::XmlOrganizer->value, ProcessingTool::XmlAnalyzer->value], true) ? [] : app(PurchaseService::class)->packages(),
        'checkout_available' => ! in_array($tool, [ProcessingTool::XmlToPdf->value, ProcessingTool::XmlOrganizer->value, ProcessingTool::XmlAnalyzer->value], true) && app(StripeCheckoutGateway::class)->available(),
    ]]);
})->whereIn('tool', array_column(ProcessingTool::cases(), 'value'))->middleware('throttle:60,1');

Route::post('/processings', [ProcessingController::class, 'store'])->middleware(['auth:sanctum', 'throttle:processing-create']);
Route::get('/processings', [AccountController::class, 'processings'])->middleware(['auth:sanctum', 'throttle:processing-access']);

Route::prefix('processings/{processing}')->whereUuid('processing')
    ->middleware(['auth:sanctum', 'throttle:processing-access', RequireProcessingOwner::class])
    ->group(function () {
        Route::get('/', [ProcessingController::class, 'show']);
        Route::post('/start', [ProcessingController::class, 'start'])->middleware('throttle:processing-create');
        Route::get('/files', [ProcessingController::class, 'files']);
        Route::get('/result', [ProcessingController::class, 'result']);
        Route::get('/analysis', [XmlAnalysisController::class, 'show']);
        Route::get('/analysis/export', [XmlAnalysisController::class, 'export']);
        Route::get('/pdf/{input}', [ProcessingController::class, 'pdf'])->whereUuid('input');
        Route::get('/purchase', [BillingController::class, 'show']);
        Route::post('/files', [ProcessingController::class, 'upload'])->middleware('throttle:processing-upload');
        Route::delete('/files', [ProcessingController::class, 'destroyFiles']);
    });

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'feitoo-api',
    ]);
});

Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::get('/user', [AuthController::class, 'me'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->prefix('account/subscription')->group(function () {
    Route::get('/', [SubscriptionController::class, 'show'])->middleware('throttle:subscription-status');
    Route::post('/checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle:10,1');
    Route::post('/portal', [SubscriptionController::class, 'portal'])->middleware('throttle:10,1');
});

Route::get('/account/usage', [BillingController::class, 'usage'])->middleware(['auth:sanctum', 'throttle:processing-access']);
Route::get('/account/purchases', [BillingController::class, 'purchases'])->middleware(['auth:sanctum', 'throttle:processing-access']);
Route::post('/stripe/webhook', StripeWebhookController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class);
