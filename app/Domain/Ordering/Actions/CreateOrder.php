<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\ProductUnavailable;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payments\Actions\ReplayPendingEvents;
use App\Support\Log\PaymentLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates an order.
 *
 * Stock is intentionally not reserved here. The supplier owns the key pool, so
 * a reservation would require distributed locking and timeout-based release for
 * mostly unpaid orders. Shortages are detected during delivery and handled as
 * the recoverable out_of_stock outcome defined by the contract.
 */
final readonly class CreateOrder
{
    public function __construct(
        private ReplayPendingEvents $replayPendingEvents,
    ) {}

    public function handle(string $sku, ?string $customerEmail = null): Order
    {
        $order = DB::transaction(function () use ($sku, $customerEmail): Order {
            $product = Product::query()
                ->where('sku', $sku)
                ->where('is_active', true)
                ->first();

            if ($product === null) {
                throw ProductUnavailable::sku($sku);
            }

            // Freeze the price when the order is created so later catalog changes
            // cannot alter an amount that has already been presented for payment.
            return Order::create([
                'public_id' => Order::newPublicId(),
                'sku' => $product->sku,
                'quantity' => 1,
                'amount_minor' => $product->price_minor,
                'currency' => $product->currency,
                'status' => OrderStatus::Created,
                'customer_email' => $customerEmail,
            ]);
        });

        PaymentLog::info('order.created', [
            'order_id' => $order->public_id,
            'sku' => $order->sku,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
        ]);

        // A webhook may arrive before the order. Replay pending events for
        // this public ID immediately instead of waiting for the scheduler.
        // A replay failure must not turn a committed purchase into a 500;
        // the scheduled sweep will retry the still-pending event.
        try {
            $this->replayPendingEvents->forOrder($order->public_id);
        } catch (\Throwable $e) {
            PaymentLog::error('order.pending_replay_failed', [
                'order_id' => $order->public_id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $order;
    }
}
