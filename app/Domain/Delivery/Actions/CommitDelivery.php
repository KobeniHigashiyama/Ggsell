<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Catalog\Actions\SyncStockFlag;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Support\Log\DeliveryLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place where a completed delivery is persisted.
 *
 * Attempts, retries, and fallbacks remain reversible until this point. The
 * irreversible write is centralized and protected by unique indexes on both
 * the order and the code.
 */
final readonly class CommitDelivery
{
    public function __construct(
        private PostTransaction $postTransaction,
        private SyncStockFlag $syncStockFlag,
    ) {}

    /**
     * @return bool True when this call recorded delivery; false if already delivered.
     */
    public function handle(Order $order, DeliveryAttempt $attempt, string $code): bool
    {
        return DB::transaction(function () use ($order, $attempt, $code): bool {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            try {
                // A nested transaction creates the savepoint required to recover
                // from a PostgreSQL constraint violation without aborting the caller.
                $delivery = DB::transaction(fn (): Delivery => Delivery::create([
                    'order_id' => $order->id,
                    'code' => $code,
                    'delivery_attempt_id' => $attempt->id,
                    'supplier' => $attempt->supplier,
                    'delivered_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // If the winning transaction stored the same code, concurrent
                // reconciliation of one request_id caused no loss and no orphan.
                if (Delivery::query()->where('order_id', $order->id)->value('code') === $code) {
                    DeliveryLog::info('delivery.race_lost_same_code', [
                        'order_id' => $order->public_id,
                        'request_id' => $attempt->request_id,
                    ]);

                    return false;
                }

                // Otherwise the paid code is orphaned and must remain visible in
                // reconciliation. insertOrIgnore avoids duplicate reports on retries.
                DB::table('orphaned_codes')->insertOrIgnore([
                    'order_id' => $order->id,
                    'delivery_attempt_id' => $attempt->id,
                    'supplier' => $attempt->supplier->value,
                    'code' => $code,
                    'reason' => 'lost_delivery_race',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DeliveryLog::warning('delivery.race_lost', [
                    'order_id' => $order->public_id,
                    'supplier' => $attempt->supplier->value,
                    'request_id' => $attempt->request_id,
                ]);

                return false;
            }

            // The delivery row makes the delivered transition mandatory. A failed
            // transition must throw and roll back the delivery instead of leaving
            // a delivered product attached to an inconsistent order status.
            $order->transitionTo(OrderStatus::Delivered);
            $order->delivered_at = now();
            $order->failure_reason = null;
            $order->save();

            // Delivery closes the customer liability and recognizes revenue.
            $this->postTransaction->handle(
                lines: [
                    LedgerLine::debit(Account::CustomerLiability, $order->amount_minor),
                    LedgerLine::credit(Account::Revenue, $order->amount_minor),
                ],
                currency: $order->currency,
                refType: 'delivery',
                refId: (string) $order->id,
                orderId: $order->id,
            );

            // The stock projection is not authoritative, so clamp drift at zero.
            DB::table('product_stock')
                ->where('sku', $order->sku)
                ->where('available_count', '>', 0)
                ->update([
                    'available_count' => DB::raw('available_count - 1'),
                    'issued_count' => DB::raw('issued_count + 1'),
                    'updated_at' => now(),
                ]);

            // The availability flag changes only when stock crosses zero, so most
            // deliveries do not update a product row.
            $this->syncStockFlag->handle($order->sku);

            DeliveryLog::info('delivery.committed', [
                'order_id' => $order->public_id,
                'supplier' => $attempt->supplier->value,
                'request_id' => $attempt->request_id,
                'attempt_no' => $attempt->attempt_no,
                'delivery_id' => $delivery->id,
            ]);

            return true;
        });
    }
}
