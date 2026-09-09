<?php

declare(strict_types=1);

namespace App\Domain\History\Queries;

use App\Domain\History\Enums\OrderEventType;
use App\Domain\History\Models\OrderEvent;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Support\Carbon;

/**
 * Rebuilds an order and its money as they were at a chosen moment.
 *
 * The answer is folded from the events up to that instant rather than filtered
 * out of today's state, which is what makes it a reconstruction. An order whose
 * log carries its composition is answered entirely from the log; one created
 * before the log recorded item identifiers falls back to the live rows for the
 * list of lines alone, and says so in the response.
 */
final readonly class OrderStateAt
{
    /**
     * @return array{
     *     order_id: string,
     *     existed: bool,
     *     as_of: string,
     *     status: string|null,
     *     money: array{paid_minor: int, delivered_minor: int, refunded_minor: int, outstanding_minor: int},
     *     items: list<array{item_id: string, sku: string, status: string, amount_minor: int}>,
     *     events: list<array{type: string, occurred_at: string, payload: array<string, mixed>}>
     * }
     */
    public function handle(Order $order, Carbon $moment): array
    {
        /** @var list<OrderEvent> $events */
        $events = OrderEvent::query()
            ->where('order_id', $order->id)
            ->upTo($moment)
            ->orderBy('id')
            ->get()
            ->all();

        if ($events === []) {
            // Before its first event the order did not exist as far as history is
            // concerned, which is a different answer from "it exists and is empty".
            return [
                'order_id' => $order->public_id,
                'existed' => false,
                'as_of' => $moment->toIso8601String(),
                'status' => null,
                'money' => ['paid_minor' => 0, 'delivered_minor' => 0, 'refunded_minor' => 0, 'outstanding_minor' => 0],
                'items' => [],
                'events' => [],
            ];
        }

        $lines = $this->foldItemStates($order, $events);
        $money = $this->foldMoney($events);

        return [
            'order_id' => $order->public_id,
            'existed' => true,
            'as_of' => $moment->toIso8601String(),
            'status' => $this->foldStatus($events)->value,
            'money' => $money,
            'items' => $lines['items'],
            // False means the order predates item identifiers in the log and its
            // list of lines was read from the live rows.
            'composition_from_log' => $lines['from_log'],
            'events' => array_map(static fn (OrderEvent $event): array => [
                'type' => $event->type->value,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'payload' => $event->payload,
            ], $events),
        ];
    }

    /**
     * @param  list<OrderEvent>  $events
     */
    private function foldStatus(array $events): OrderStatus
    {
        $status = OrderStatus::Created;

        foreach ($events as $event) {
            $status = match ($event->type) {
                OrderEventType::PaymentApplied => OrderStatus::Paid,
                OrderEventType::PaymentFailed => OrderStatus::PaymentFailed,
                // A derived status supersedes whatever the payment half implied.
                OrderEventType::OrderStatusChanged => OrderStatus::from($event->payload['to']),
                default => $status,
            };
        }

        return $status;
    }

    /**
     * Rebuilds the lines of the order and what each of them was doing.
     *
     * Composition comes from the order.created event when it carries identifiers.
     * Line states are folded from the events that changed them, so a line that
     * was mid-retry at that moment reads as delivering rather than as an
     * indistinguishable "awaiting".
     *
     * @param  list<OrderEvent>  $events
     * @return array{items: list<array{item_id: string, sku: string, status: string, amount_minor: int}>, from_log: bool}
     */
    private function foldItemStates(Order $order, array $events): array
    {
        $composition = $this->composition($order, $events);
        $states = [];

        foreach ($events as $event) {
            if ($event->order_item_id === null) {
                continue;
            }

            $states[$event->order_item_id] = match ($event->type) {
                OrderEventType::ItemDelivered => 'delivered',
                OrderEventType::ItemRefunded => 'refunded',
                OrderEventType::ItemStatusChanged => (string) $event->payload['to'],
                default => $states[$event->order_item_id] ?? null,
            };
        }

        // Events carry internal item ids; the answer speaks in public ones.
        $publicIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->pluck('public_id', 'id');

        $byPublicId = [];

        foreach ($states as $itemId => $state) {
            if ($state !== null && $publicIds->has($itemId)) {
                $byPublicId[$publicIds[$itemId]] = $state;
            }
        }

        return [
            'items' => array_map(static fn (array $line): array => [
                'item_id' => $line['item_id'],
                'sku' => $line['sku'],
                // A line with no state event yet had not started: it was ordered
                // and paid for and nothing had happened to it.
                'status' => $byPublicId[$line['item_id']] ?? 'pending',
                'amount_minor' => $line['amount_minor'],
            ], $composition['items']),
            'from_log' => $composition['from_log'],
        ];
    }

    /**
     * The lines the order was made of.
     *
     * @param  list<OrderEvent>  $events
     * @return array{items: list<array{item_id: string, sku: string, amount_minor: int}>, from_log: bool}
     */
    private function composition(Order $order, array $events): array
    {
        foreach ($events as $event) {
            if ($event->type !== OrderEventType::OrderCreated) {
                continue;
            }

            $lines = $event->payload['items'] ?? [];

            if ($lines !== [] && isset($lines[0]['item_id'])) {
                return [
                    'items' => array_map(static fn (array $line): array => [
                        'item_id' => (string) $line['item_id'],
                        'sku' => (string) $line['sku'],
                        'amount_minor' => (int) $line['amount_minor'],
                    ], $lines),
                    'from_log' => true,
                ];
            }
        }

        // Recorded before the log carried identifiers. The live rows are the only
        // source left, and the caller is told.
        return [
            'items' => OrderItem::query()
                ->where('order_id', $order->id)
                ->orderBy('position')
                ->get()
                ->map(fn (OrderItem $item): array => [
                    'item_id' => $item->public_id,
                    'sku' => $item->sku,
                    'amount_minor' => $item->amount_minor,
                ])
                ->all(),
            'from_log' => false,
        ];
    }

    /**
     * @param  list<OrderEvent>  $events
     * @return array{paid_minor: int, delivered_minor: int, refunded_minor: int, outstanding_minor: int}
     */
    private function foldMoney(array $events): array
    {
        $paid = 0;
        $delivered = 0;
        $refunded = 0;

        foreach ($events as $event) {
            $amount = (int) ($event->payload['amount_minor'] ?? 0);

            match ($event->type) {
                OrderEventType::PaymentApplied => $paid += $amount,
                OrderEventType::ItemDelivered => $delivered += $amount,
                OrderEventType::ItemRefunded => $refunded += $amount,
                default => null,
            };
        }

        return [
            'paid_minor' => $paid,
            'delivered_minor' => $delivered,
            'refunded_minor' => $refunded,
            // What the customer was still owed at that moment, in either form.
            'outstanding_minor' => $paid - $delivered - $refunded,
        ];
    }
}
