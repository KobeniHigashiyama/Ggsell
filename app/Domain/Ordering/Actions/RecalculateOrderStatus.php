<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\History\Actions\RecordOrderEvent;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\IllegalTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Support\Carbon;

/**
 * The single writer of the delivery half of an order status.
 *
 * Every action that changes an item state calls this before committing, so the
 * order row is never observed disagreeing with its items. Centralizing it also
 * means the aggregation rules exist once instead of being re-implemented by
 * delivery, refunds, and recovery.
 *
 * The caller must already hold the order row lock inside a transaction. Without
 * it two items settling concurrently could each compute a status from a stale
 * view of the other and write the loser's result last.
 */
final readonly class RecalculateOrderStatus
{
    public function __construct(
        private RecordOrderEvent $recordEvent,
    ) {}

    public function handle(Order $order): OrderStatus
    {
        // Orders whose money has not arrived belong to the payment state machine.
        // Deriving here would resurrect a cancelled order as soon as an item row
        // was touched.
        if (! $order->status->moneyReceived()) {
            return $order->status;
        }

        /** @var list<OrderItem> $items */
        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('position')
            ->get()
            ->all();

        $derived = OrderStatus::deriveFrom(array_map(
            static fn (OrderItem $item): OrderItemStatus => $item->status,
            $items,
        ));

        if ($order->status->isTerminal() && $derived !== $order->status) {
            // A terminal order holds only terminal items, so its derivation cannot
            // change. Reaching this means an item was mutated after settlement.
            throw IllegalTransition::between($order, $order->status, $derived);
        }

        $previous = $order->status;
        $order->status = $derived;
        $order->failure_reason = $this->failureReason($items, $derived);
        $order->delivered_at = $this->deliveredAt($items, $derived);

        // Once terminal the order owes nothing in either direction. Recorded
        // first time only, so a later recalculation cannot move the timestamp.
        if ($derived->isTerminal()) {
            $order->settled_at ??= now();
        }

        $order->save();

        if ($derived !== $previous) {
            // Recorded here because this is the only writer of the delivery half
            // of the status, so the log cannot disagree with the row.
            $this->recordEvent->handle(
                type: OrderEventType::OrderStatusChanged,
                orderId: $order->id,
                orderItemId: null,
                payload: ['from' => $previous->value, 'to' => $derived->value],
                refType: 'order_status',
                // A sequence rather than the pair of statuses: an order can move
                // back and forth between the same two while its lines are retried,
                // and every one of those transitions is a distinct fact.
                refId: $order->id.':'.$this->recordEvent->nextSequence(OrderEventType::OrderStatusChanged, $order->id),
            );
        }

        return $derived;
    }

    /**
     * Surfaces why an order is not fully delivered.
     *
     * The first unsettled or refunded reason is reported rather than a synthetic
     * summary: for a single-item order it reproduces the stage-1 value exactly,
     * and for a multi-item order support staff get a real reason to act on
     * instead of "mixed".
     *
     * @param  list<OrderItem>  $items
     */
    private function failureReason(array $items, OrderStatus $derived): ?string
    {
        if ($derived === OrderStatus::Delivered) {
            return null;
        }

        foreach ($items as $item) {
            if ($item->status !== OrderItemStatus::Delivered && $item->failure_reason !== null) {
                return $item->failure_reason;
            }
        }

        return null;
    }

    /**
     * The moment the order stopped owing the customer a product.
     *
     * Only set once the order is terminal and something was actually delivered,
     * so a partially delivered order still reports when its last code was handed
     * over. A fully refunded order never has a delivery timestamp.
     *
     * @param  list<OrderItem>  $items
     */
    private function deliveredAt(array $items, OrderStatus $derived): ?Carbon
    {
        if (! in_array($derived, [OrderStatus::Delivered, OrderStatus::PartiallyDelivered], strict: true)) {
            return null;
        }

        $timestamps = array_filter(array_map(
            static fn (OrderItem $item): ?Carbon => $item->delivered_at,
            $items,
        ));

        return $timestamps === [] ? null : max($timestamps);
    }
}
