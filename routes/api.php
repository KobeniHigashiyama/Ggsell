<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DeliveryProgressController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderHistoryController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Stub\Payment\RefundController;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('products', [ProductController::class, 'index']);

    Route::post('orders', [OrderController::class, 'store'])
        ->middleware(EnsureIdempotentRequest::class);
    Route::get('orders/{publicId}', [OrderController::class, 'show']);

    Route::post('webhooks/payment', PaymentWebhookController::class);

    Route::get('ops/reconciliation', [ReconciliationController::class, 'index']);
    Route::get('ops/delivery/progress', DeliveryProgressController::class);

    // Reading the past: one order at a moment, and what a period added up to.
    Route::get('ops/orders/{publicId}/at', [OrderHistoryController::class, 'orderAt']);
    Route::get('ops/reports/period', [OrderHistoryController::class, 'period']);
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
    Route::get('rate', [SupplierIssueController::class, 'rate']);

    // What the supplier believes it did with a request, and a way to hand a code
    // back. Both exist because the core cannot take an issue() response on trust.
    Route::get('requests/{requestId}', [SupplierIssueController::class, 'verify']);
    Route::post('return', [SupplierIssueController::class, 'returnCode']);
});

/*
|--------------------------------------------------------------------------
| Payment gateway stub
|--------------------------------------------------------------------------
| Outbound money movement. Same boundary rules as the supplier stub: its own
| schema, no relations to core tables, reachable only over HTTP.
*/
Route::post('payments/refund', [RefundController::class, 'refund']);
