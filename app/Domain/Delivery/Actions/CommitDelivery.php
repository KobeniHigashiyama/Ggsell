<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Catalog\Actions\SyncStockFlag;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\History\Actions\RecordOrderEvent;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ordering\Actions\RecalculateOrderStatus;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Support\Log\DeliveryLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place where a completed delivery is persisted.
 *
 * Attempts, retries, and fallbacks remain reversible until this point. The
 * irreversible write is centralized and protected by two unique indexes:
 * UNIQUE(order_item_id) allows one delivery per item, and UNIQUE(code) is global
 * so one code can never reach two customers no matter what the supplier sends.
 */
final readonly class CommitDelivery
{
    public function __construct(
        private PostTransaction $postTransaction,
        private SyncStockFlag $syncStockFlag,
        private RecalculateOrderStatus $recalculateOrderStatus,
        private RecordOrderEvent $recordEvent,
    ) {}

    public function handle(OrderItem $item, DeliveryAttempt $attempt, string $code): DeliveryCommitResult
    {
        return DB::transaction(function () use ($item, $attempt, $code): DeliveryCommitResult {
            // Lock order before item, everywhere, so concurrent items of one order
            // cannot deadlock against each other.
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            // Then the code itself, because it is shared with a process this one
            // knows nothing about: the sweep that hands stranded codes back to
            // their supplier. Row locks cannot order those two decisions — they
            // touch different rows — so without this a delivery and a revocation
            // of the same code can both look correct and interleave into a
            // customer holding a code that was cancelled a moment later.
            $this->lockCode($code);

            if ($this->isStranded($code)) {
                // Somebody already judged this code undeliverable, and may have
                // already handed it back. A supplier offering it again is offering
                // something it no longer owns.
                $this->recordOrphan($item, $attempt, $code, 'code_already_stranded');

                DeliveryLog::warning('delivery.refused_stranded_code', [
                    'order_id' => $order->public_id,
                    'item_id' => $item->public_id,
                    'request_id' => $attempt->request_id,
                ]);

                return DeliveryCommitResult::CodeTaken;
            }

            if ($this->refundHasStarted($item)) {
                // The mirror of RefundOrderItem's own guard, placed here because
                // this is the only writer of deliveries. Keeping it in each caller
                // is what let the audit sweep slip past it and produce an item
                // that was both delivered and refunded.
                $this->recordOrphan($item, $attempt, $code, 'refund_in_flight');

                DeliveryLog::error('delivery.refused_refund_in_flight', [
                    'order_id' => $order->public_id,
                    'item_id' => $item->public_id,
                    'request_id' => $attempt->request_id,
                ]);

                return DeliveryCommitResult::RefusedRefundInFlight;
            }

            if (! $order->status->moneyReceived()) {
                // Handing over a product for an order whose money never arrived
                // would post revenue against no cash. Fail loudly so the delivery
                // row and the ledger entry roll back together.
                throw IllegalTransition::between($order, $order->status, OrderStatus::Delivered);
            }

            try {
                // A nested transaction creates the savepoint required to recover
                // from a PostgreSQL constraint violation without aborting the caller.
                $delivery = DB::transaction(fn (): Delivery => Delivery::create([
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'code' => $code,
                    'delivery_attempt_id' => $attempt->id,
                    'supplier' => $attempt->supplier,
                    'delivered_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                return $this->classifyRejectedCode($order, $item, $attempt, $code);
            }

            // The delivery row makes the delivered transition mandatory. A failed
            // transition must throw and roll back the delivery instead of leaving
            // a delivered product attached to an inconsistent item.
            $item->transitionTo(OrderItemStatus::Delivered);
            $item->supplier = $attempt->supplier->value;
            $item->delivered_at = now();
            $item->settled_at = now();
            $item->failure_reason = null;
            $item->save();

            // Delivery closes this item's share of the customer liability and
            // recognizes it as revenue.
            $this->postTransaction->handle(
                lines: [
                    LedgerLine::debit(Account::CustomerLiability, $item->amount_minor),
                    LedgerLine::credit(Account::Revenue, $item->amount_minor),
                ],
                currency: $item->currency,
                refType: 'delivery_item',
                refId: (string) $item->id,
                orderId: $item->order_id,
            );

            $this->recordEvent->handle(
                type: OrderEventType::ItemDelivered,
                orderId: $item->order_id,
                orderItemId: $item->id,
                payload: [
                    'sku' => $item->sku,
                    'amount_minor' => $item->amount_minor,
                    'currency' => $item->currency,
                    'supplier' => $attempt->supplier->value,
                    // The code itself never enters the log: history is read by
                    // support and monitoring, and a code is the product.
                    'request_id' => $attempt->request_id,
                ],
                refType: 'delivery_item',
                refId: (string) $item->id,
            );

            $this->consumeStock($item->sku);
            $this->recalculateOrderStatus->handle($order);

            DeliveryLog::info('delivery.committed', [
                'order_id' => $order->public_id,
                'item_id' => $item->public_id,
                'supplier' => $attempt->supplier->value,
                'request_id' => $attempt->request_id,
                'attempt_no' => $attempt->attempt_no,
                'delivery_id' => $delivery->id,
            ]);

            return DeliveryCommitResult::Committed;
        });
    }

    /**
     * Decides which unique index rejected the insert, and what it means.
     *
     * The two constraints describe different incidents. A conflict on the item
     * means someone else delivered it first, which is a benign race unless our
     * code differs, in which case the paid code must stay visible. A conflict on
     * the code alone means the supplier handed us a code that is already in
     * someone else's order: nothing is delivered here and the customer is still
     * owed a working code.
     */
    private function classifyRejectedCode(Order $order, OrderItem $item, DeliveryAttempt $attempt, string $code): DeliveryCommitResult
    {
        $deliveredCode = Delivery::query()->where('order_item_id', $item->id)->value('code');

        if ($deliveredCode === $code) {
            // Concurrent reconciliation of one request_id caused no loss and no orphan.
            DeliveryLog::info('delivery.race_lost_same_code', [
                'order_id' => $order->public_id,
                'item_id' => $item->public_id,
                'request_id' => $attempt->request_id,
            ]);

            return DeliveryCommitResult::AlreadyDelivered;
        }

        $wasDelivered = $deliveredCode !== null;

        $this->recordOrphan(
            $item,
            $attempt,
            $code,
            $wasDelivered ? 'lost_delivery_race' : 'code_belongs_to_another_item',
        );

        DeliveryLog::warning($wasDelivered ? 'delivery.race_lost' : 'delivery.code_already_owned', [
            'order_id' => $order->public_id,
            'item_id' => $item->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
        ]);

        return $wasDelivered
            ? DeliveryCommitResult::AlreadyDelivered
            : DeliveryCommitResult::CodeTaken;
    }

    /**
     * Serializes every decision about one code, across processes.
     *
     * A transaction-scoped advisory lock rather than a row lock, because the
     * thing being protected is not a row: delivering a code and returning it are
     * decisions taken in different tables by different schedules, and only the
     * code itself is common to both.
     */
    private function lockCode(string $code): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$code]);
    }

    /** True when this code has already been recorded as undeliverable. */
    private function isStranded(string $code): bool
    {
        return DB::table('orphaned_codes')->where('code', $code)->exists();
    }

    /**
     * True when money for this item is already on its way back.
     *
     * Only a definitively rejected refund proves it stayed with us; pending and
     * unknown both mean the gateway may already have moved it.
     */
    private function refundHasStarted(OrderItem $item): bool
    {
        return Refund::query()
            ->where('order_item_id', $item->id)
            ->where('status', '!=', RefundStatus::Failed->value)
            ->exists();
    }

    /**
     * Keeps a paid but undeliverable code visible for reconciliation.
     *
     * insertOrIgnore avoids duplicate reports when the same attempt is retried.
     */
    private function recordOrphan(OrderItem $item, DeliveryAttempt $attempt, string $code, string $reason): void
    {
        DB::table('orphaned_codes')->insertOrIgnore([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'delivery_attempt_id' => $attempt->id,
            'supplier' => $attempt->supplier->value,
            'code' => $code,
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function consumeStock(string $sku): void
    {
        // The stock projection is not authoritative, so clamp drift at zero.
        DB::table('product_stock')
            ->where('sku', $sku)
            ->where('available_count', '>', 0)
            ->update([
                'available_count' => DB::raw('available_count - 1'),
                'issued_count' => DB::raw('issued_count + 1'),
                'updated_at' => now(),
            ]);

        // The availability flag changes only when stock crosses zero, so most
        // deliveries do not update a product row.
        $this->syncStockFlag->handle($sku);
    }
}
