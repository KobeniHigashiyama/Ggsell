<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('products', [ProductController::class, 'index']);

    Route::post('orders', [OrderController::class, 'store'])
        ->middleware(EnsureIdempotentRequest::class);
    Route::get('orders/{publicId}', [OrderController::class, 'show']);

    Route::post('webhooks/payment', PaymentWebhookController::class);

    Route::get('ops/reconciliation', [ReconciliationController::class, 'index']);
    Route::post('ops/orders/{publicId}/redeliver', [ReconciliationController::class, 'redeliver']);
    Route::post('ops/orphaned-codes/{id}/resolve', [ReconciliationController::class, 'resolveOrphan']);
});

/*
|--------------------------------------------------------------------------
| Supplier stubs
|--------------------------------------------------------------------------
| This represents an external service: it owns a separate Postgres schema, has
| no relations to core tables, and communicates only over HTTP. Production would
| deploy it separately; sharing a process here keeps the local stack smaller
| while preserving the important boundary: no shared state.
*/
Route::prefix('suppliers/{supplier}')->group(function (): void {
    Route::post('issue', [SupplierIssueController::class, 'issue']);
    Route::get('stock', [SupplierIssueController::class, 'stock']);
});
