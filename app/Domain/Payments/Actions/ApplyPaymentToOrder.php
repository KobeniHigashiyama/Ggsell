<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\PaymentOutcome;
use App\Jobs\FulfilOrderJob;
use App\Support\Log\PaymentLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a payment event to an order.
 *
 * This is the system's serialization point. Concurrent webhooks with distinct
 * event IDs all pass deduplication, but SELECT ... FOR UPDATE on the order lets
 * only one request perform created -> paid. The rest observe the new state and
 * return no_op.
 *
 * Ledger entries and the status transition share a transaction so reconciliation
 * never observes a transient mismatch between them.
 */
final readonly class ApplyPaymentToOrder
{
    public function __construct(
        private PostTransaction $postTransaction,
    ) {}

    public function handle(PaymentEvent $event): PaymentOutcome
    {
        return DB::transaction(function () use ($event): PaymentOutcome {
            // Lock the event row because the same event ID may be processed again
            // before the first delivery commits its outcome. Without this lock, a
            // later request could overwrite the outcome recorded by the request
            // that applied the payment. Locks are always acquired event first,
            // then order, so this flow has a consistent lock order.
            $event = PaymentEvent::query()->lockForUpdate()->find($event->id);

            if ($event === null) {
                return PaymentOutcome::Duplicate;
            }

            if ($event->processed_at !== null) {
                // Another request processed the event while this one waited for
                // the lock. Return Duplicate because this invocation changed nothing.
                return PaymentOutcome::Duplicate;
            }

            $order = Order::query()
                ->where('public_id', $event->order_public_id)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                return $this->park($event);
            }

            $outcome = $this->applyToLockedOrder($event, $order);

            $event->forceFill([
                'order_id' => $order->id,
                'outcome' => $outcome->value,
                'processed_at' => now(),
            ])->save();

            PaymentLog::info('payment_event.processed', [
                'event_id' => $event->event_id,
                'order_id' => $order->public_id,
                'webhook_status' => $event->status,
                'outcome' => $outcome->value,
                'order_status' => $order->status->value,
            ]);

            return $outcome;
        });
    }

    /**
     * Handles a webhook that arrived before its order.
     *
     * The event keeps processed_at = null and remains eligible for replay. Accept
     * it instead of returning an error to the payment system, then process it when
     * the order appears.
     */
    private function park(PaymentEvent $event): PaymentOutcome
    {
        $event->forceFill(['outcome' => PaymentOutcome::PendingOrder->value])->save();

        PaymentLog::warning('payment_event.parked_no_order', [
            'event_id' => $event->event_id,
            'order_id' => $event->order_public_id,
        ]);

        return PaymentOutcome::PendingOrder;
    }

    private function applyToLockedOrder(PaymentEvent $event, Order $order): PaymentOutcome
    {
        // Webhooks may arrive out of order. An older event cannot replace the
        // state produced by a newer event.
        $eventAt = $event->occurred_at ?? $event->received_at;

        if ($order->last_payment_event_at !== null && $eventAt->lt($order->last_payment_event_at)) {
            return PaymentOutcome::Stale;
        }

        // Validate the amount only for successful payments. It carries no useful
        // meaning for a failure and must not prevent the failure transition.
        $amountMatters = $event->status === 'paid';

        if ($amountMatters && ($event->amount_minor !== $order->amount_minor || $event->currency !== $order->currency)) {
            // A mismatched payment cannot trigger delivery or cancellation. Keep
            // it visible for manual review through reconciliation.
            PaymentLog::error('payment_event.amount_mismatch', [
                'event_id' => $event->event_id,
                'order_id' => $order->public_id,
                'expected_minor' => $order->amount_minor,
                'received_minor' => $event->amount_minor,
                'expected_currency' => $order->currency,
                'received_currency' => $event->currency,
            ]);

            return PaymentOutcome::Mismatch;
        }

        return $event->status === 'paid'
            ? $this->markPaid($event, $order, $eventAt)
            : $this->markFailed($event, $order, $eventAt);
    }

    private function markPaid(PaymentEvent $event, Order $order, Carbon $eventAt): PaymentOutcome
    {
        // The state machine allows paid only from created or, for a late success,
        // payment_failed. Other states mean the payment is already accounted for.
        if (! $order->status->canTransitionTo(OrderStatus::Paid)) {
            return PaymentOutcome::NoOp;
        }

        if ($order->status === OrderStatus::PaymentFailed) {
            // A successful payment after a failure must still be honored so the
            // customer is not charged without receiving the product.
            PaymentLog::warning('payment.succeeded_after_failure', [
                'event_id' => $event->event_id,
                'order_id' => $order->public_id,
            ]);
        }

        $order->transitionTo(OrderStatus::Paid);
        $order->paid_at ??= now();
        $order->last_payment_event_at = $eventAt;
        $order->save();

        // Receiving cash creates a delivery liability. Revenue is recognized only
        // when the product is actually delivered.
        $this->postTransaction->handle(
            lines: [
                LedgerLine::debit(Account::Cash, $order->amount_minor),
                LedgerLine::credit(Account::CustomerLiability, $order->amount_minor),
            ],
            currency: $order->currency,
            refType: 'payment_event',
            refId: $event->event_id,
            orderId: $order->id,
        );

        // Dispatch after commit so a rolled-back payment cannot enqueue delivery.
        FulfilOrderJob::dispatch($order->id)->afterCommit();

        return PaymentOutcome::Applied;
    }

    private function markFailed(PaymentEvent $event, Order $order, Carbon $eventAt): PaymentOutcome
    {
        if (! $order->status->canTransitionTo(OrderStatus::PaymentFailed)) {
            // A failure after payment is either stale or a refund request. Refunds use
            // a separate manual flow rather than reversing the order status here.
            PaymentLog::warning('payment.failure_after_money_received', [
                'event_id' => $event->event_id,
                'order_id' => $order->public_id,
                'order_status' => $order->status->value,
            ]);

            return PaymentOutcome::NoOp;
        }

        $order->transitionTo(OrderStatus::PaymentFailed);
        $order->failure_reason = 'payment_declined';
        $order->last_payment_event_at = $eventAt;
        $order->save();

        return PaymentOutcome::Applied;
    }
}
