<?php

use App\Enums\ProcessingTool;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ProcessingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\XmlAnalysisController;
use App\Http\Middleware\RequireProcessingOwner;
use App\Services\EntitlementService;
use App\Services\PurchaseService;
use App\Services\StripeCheckoutGateway;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::get('/tools/{tool}/config', function (string $tool) {
    if ($tool === ProcessingTool::DocxToPdf->value) {
        return response()->json(['data' => [
            'max_files' => min(config('documents.max_files'), config('feitoo.uploads.max_files')),
            'max_processing_files' => config('documents.max_files'),
            'max_request_size_kb' => config('feitoo.uploads.max_request_size_kb'),
            'max_total_size_kb' => config('documents.max_total_size_kb'),
            'max_file_size_kb' => config('documents.max_file_size_kb'),
            'ttl_minutes' => config('feitoo.processing.ttl_minutes'),
            'free' => config('documents.limits.free'), 'pro' => config('documents.limits.pro'),
            'packages' => [], 'checkout_available' => false,
        ]]);
    }

    return response()->json(['data' => [
        'max_files' => config('feitoo.uploads.max_files'),
        'max_request_size_kb' => config('feitoo.uploads.max_request_size_kb'),
        'max_processing_files' => config('feitoo.uploads.max_processing_files'),
        'max_processing_items' => config('feitoo.uploads.max_processing_items'),
        'max_total_size_kb' => config('feitoo.uploads.max_total_size_kb'),
        'max_file_size_kb' => config('feitoo.uploads.max_file_size_kb'),
        'ttl_minutes' => config('feitoo.processing.ttl_minutes'),
        'free' => app(EntitlementService::class)->freeLimits(ProcessingTool::from($tool)),
        'packages' => in_array($tool, [ProcessingTool::DocxToPdf->value, ProcessingTool::XmlToPdf->value, ProcessingTool::XmlOrganizer->value, ProcessingTool::XmlAnalyzer->value], true) ? [] : app(PurchaseService::class)->packages(),
        'checkout_available' => ! in_array($tool, [ProcessingTool::DocxToPdf->value, ProcessingTool::XmlToPdf->value, ProcessingTool::XmlOrganizer->value, ProcessingTool::XmlAnalyzer->value], true) && app(StripeCheckoutGateway::class)->available(),
    ]]);
})->whereIn('tool', array_values(array_filter(array_column(ProcessingTool::cases(), 'value'), fn ($tool) => $tool !== ProcessingTool::QuotationGenerator->value)))->middleware('throttle:60,1');

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

Route::get('/account/usage', [BillingController::class, 'usage'])->middleware(['auth:sanctum', 'throttle:processing-access']);
Route::get('/account/purchases', [BillingController::class, 'purchases'])->middleware(['auth:sanctum', 'throttle:processing-access']);
Route::post('/stripe/webhook', StripeWebhookController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class);
