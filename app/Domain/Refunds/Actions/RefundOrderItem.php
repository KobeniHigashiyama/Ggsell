<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\History\Actions\RecordOrderEvent;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ordering\Actions\RecalculateOrderStatus;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Gateways\PaymentGateway;
use App\Domain\Refunds\Gateways\RefundOutcome;
use App\Domain\Refunds\Gateways\RefundResponse;
use App\Domain\Refunds\Models\Refund;
use App\Support\Log\PaymentLog;
use Illuminate\Support\Facades\DB;

/**
 * Returns the money for one order item that could not be delivered.
 *
 * The shape mirrors delivery on purpose, because the failure modes are the same:
 *
 *   1. Write the intent, with a deterministic request id, before calling out.
 *   2. Call the gateway outside the transaction, retrying under that same id.
 *   3. Commit the item state and the ledger entries together, exactly once.
 *
 * The refund is only recognized after the gateway confirms it. An unknown outcome
 * leaves the row unfinished so recovery asks again under the same request id,
 * which the gateway answers from its registry rather than by paying twice.
 */
final readonly class RefundOrderItem
{
    public function __construct(
        private PaymentGateway $gateway,
        private PostTransaction $postTransaction,
        private RecalculateOrderStatus $recalculateOrderStatus,
        private RecordOrderEvent $recordEvent,
    ) {}

    /** @return RefundStatus|null Null when refunding this item is not allowed right now. */
    public function handle(int $orderItemId, ?string $reason = null): ?RefundStatus
    {
        $refund = $this->prepare($orderItemId, $reason);

        if ($refund === null) {
            return null;
        }

        if ($refund->status === RefundStatus::Succeeded) {
            // Another run already moved the money; only the bookkeeping may be
            // missing, and settle() is idempotent.
            return $this->settle($refund);
        }

        $response = $this->askGateway($refund);

        $refund->applyResponse($response);
        $refund->save();

        if ($refund->status !== RefundStatus::Succeeded) {
            PaymentLog::error('refund.not_completed', [
                'refund_request_id' => $refund->refund_request_id,
                'status' => $refund->status->value,
                'reason' => $response->reason,
            ]);

            return $refund->status;
        }

        return $this->settle($refund);
    }

    /**
     * Creates or resumes the refund intent.
     *
     * Returns null when refunding is not allowed. The important case is an
     * unresolved or uncommitted supplier attempt: the supplier may still have
     * issued a code for this item, and paying the customer back while a code is
     * on its way would give away both the money and the product.
     */
    private function prepare(int $orderItemId, ?string $reason): ?Refund
    {
        return DB::transaction(function () use ($orderItemId, $reason): ?Refund {
            $orderId = OrderItem::query()->whereKey($orderItemId)->value('order_id');

            if ($orderId === null) {
                return null;
            }

            $order = Order::query()->lockForUpdate()->find($orderId);
            $item = OrderItem::query()->lockForUpdate()->find($orderItemId);

            if ($order === null || $item === null || $item->status->isSettled()) {
                return null;
            }

            if (! $order->status->moneyReceived()) {
                // Nothing was received, so there is nothing to give back.
                return null;
            }

            if ($item->delivery()->exists()) {
                PaymentLog::warning('refund.refused_delivered_item', [
                    'item_id' => $item->public_id,
                ]);

                return null;
            }

            if (! $item->isRefundable((int) config('ggsell.recovery.max_fulfilment_runs'))) {
                // The scan applies the same rule when it picks candidates, but a
                // queued job can arrive after another run moved the line back into
                // delivery, and the rule has to hold at the moment money moves.
                PaymentLog::warning('refund.refused_item_still_in_delivery', [
                    'item_id' => $item->public_id,
                    'item_status' => $item->status->value,
                ]);

                return null;
            }

            if ($this->hasCodeAtRisk($item)) {
                PaymentLog::warning('refund.blocked_by_open_attempt', [
                    'item_id' => $item->public_id,
                ]);

                return null;
            }

            $refund = Refund::query()->where('order_item_id', $item->id)->lockForUpdate()->first();

            if ($refund === null) {
                return Refund::create([
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'refund_request_id' => Refund::makeRequestId($item),
                    'amount_minor' => $item->amount_minor,
                    'currency' => $item->currency,
                    'status' => RefundStatus::Pending,
                    'reason' => $reason ?? $item->failure_reason ?? 'undeliverable',
                    'requested_at' => now(),
                ]);
            }

            if ($refund->status === RefundStatus::Failed) {
                // A definitive rejection moved no money, so the same request id
                // may be offered to the gateway again.
                $refund->forceFill(['status' => RefundStatus::Pending, 'completed_at' => null])->save();
            }

            return $refund;
        });
    }

    /**
     * True when a supplier may still owe this item a code.
     *
     * Both an unresolved attempt and a succeeded attempt without a delivery mean
     * a code could yet land, and delivery plus refund for one payment is the one
     * outcome the ledger can never repair.
     */
    private function hasCodeAtRisk(OrderItem $item): bool
    {
        return $item->attempts()->unresolved()->exists()
            || $item->attempts()->holdingUsableCode()->exists();
    }

    /**
     * Retries within a single refund_request_id.
     *
     * The gateway sees the same request regardless of retry count, so a retry
     * after a timeout returns the original reference instead of paying again.
     */
    private function askGateway(Refund $refund): RefundResponse
    {
        $maxTries = (int) config('ggsell.payments.max_attempts');
        $response = RefundResponse::unknown('not_attempted', null, 0);
        $item = OrderItem::query()->findOrFail($refund->order_item_id);
        $orderPublicId = (string) Order::query()->whereKey($refund->order_id)->value('public_id');

        for ($try = 1; $try <= $maxTries; $try++) {
            $refund->tries = $try;

            $response = $this->gateway->refund(
                $refund->refund_request_id,
                $orderPublicId,
                $item->public_id,
                $refund->amount_minor,
                $refund->currency,
            );

            PaymentLog::info('refund.call_finished', [
                'refund_request_id' => $refund->refund_request_id,
                'item_id' => $item->public_id,
                'try' => $try,
                'outcome' => $response->outcome->value,
                'reason' => $response->reason,
                'http_status' => $response->httpStatus,
                'latency_ms' => $response->latencyMs,
            ]);

            if ($response->outcome !== RefundOutcome::Unknown) {
                return $response;
            }
        }

        return $response;
    }

    /**
     * Commits the refunded item and its ledger entries in one transaction.
     *
     * Idempotent: the item transition is skipped when another run already made
     * it, and the ledger write is deduplicated by (ref_type, ref_id, account).
     */
    private function settle(Refund $refund): RefundStatus
    {
        DB::transaction(function () use ($refund): void {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $item = OrderItem::query()->lockForUpdate()->findOrFail($refund->order_item_id);

            if ($item->status !== OrderItemStatus::Refunded && ! $item->tryTransitionTo(OrderItemStatus::Refunded)) {
                // The line moved somewhere unrefundable while the gateway was
                // being called. Posting anyway is how a mismatch becomes a real
                // double payout in the books, so the entries are withheld and the
                // incident is surfaced instead. The reconciliation check
                // refunds_not_settled reports it, and a later sweep retries once
                // the line is recoverable again.
                PaymentLog::error('refund.completed_but_item_not_refundable', [
                    'order_id' => $order->public_id,
                    'item_id' => $item->public_id,
                    'item_status' => $item->status->value,
                    'refund_request_id' => $refund->refund_request_id,
                ]);

                return;
            }

            $item->settled_at ??= now();
            $item->save();

            // Returning money closes this item's share of the liability and takes
            // the cash back out. Revenue is untouched: nothing was ever earned.
            $this->postTransaction->handle(
                lines: [
                    LedgerLine::debit(Account::CustomerLiability, $item->amount_minor),
                    LedgerLine::credit(Account::Cash, $item->amount_minor),
                ],
                currency: $item->currency,
                refType: 'refund_item',
                refId: (string) $item->id,
                orderId: $item->order_id,
            );

            $this->recordEvent->handle(
                type: OrderEventType::ItemRefunded,
                orderId: $item->order_id,
                orderItemId: $item->id,
                payload: [
                    'sku' => $item->sku,
                    'amount_minor' => $item->amount_minor,
                    'currency' => $item->currency,
                    'reason' => $refund->reason,
                    'gateway_reference' => $refund->gateway_reference,
                ],
                refType: 'refund_item',
                refId: (string) $item->id,
            );

            $this->recalculateOrderStatus->handle($order);

            PaymentLog::warning('refund.completed', [
                'order_id' => $order->public_id,
                'item_id' => $item->public_id,
                'refund_request_id' => $refund->refund_request_id,
                'amount_minor' => $item->amount_minor,
                'reason' => $refund->reason,
            ]);
        });

        return RefundStatus::Succeeded;
    }
}
