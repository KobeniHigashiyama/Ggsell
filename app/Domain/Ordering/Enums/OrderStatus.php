<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

use DomainException;

/**
 * Order lifecycle.
 *
 * Stage 2 splits this enum into two halves that behave differently.
 *
 * The payment half (created, paid, payment_failed) is a real state machine:
 * transitions() lists its legal edges and every change passes through
 * canTransitionTo(), which is what makes repeated webhooks safe at the domain
 * level as well as at the event_id deduplication boundary.
 *
 * The delivery half is a projection. An order holding several items may have
 * delivered, refunded, and still-retrying lines at once, so no single actor can
 * "transition" it. deriveFrom() computes the status from the item states and
 * RecalculateOrderStatus is the only writer.
 *
 * For a single-item order the derivation reproduces stage-1 behavior exactly.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';

    /** Some lines delivered, the rest refunded. Terminal. */
    case PartiallyDelivered = 'partially_delivered';

    /** Nothing delivered and the whole payment went back. Terminal. */
    case Refunded = 'refunded';

    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    /**
     * Payment-driven transitions.
     *
     * Any status outside the payment half is closed here: reaching it means the
     * money is already accounted for, so a later payment event is a no-op rather
     * than a state change.
     *
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Created->value => [self::Paid, self::PaymentFailed],

            // A later successful payment must not be ignored after a failure.
            // ApplyPaymentToOrder permits this only for a strictly newer event.
            self::PaymentFailed->value => [self::Paid],

            self::Paid->value => [],
            self::Delivering->value => [],
            self::Delivered->value => [],
            self::PartiallyDelivered->value => [],
            self::Refunded->value => [],
            self::OutOfStock->value => [],
            self::DeliveryFailed->value => [],
        ];
    }

    /**
     * Projects item states onto the order.
     *
     * A pure function so the whole status matrix is unit-testable without a
     * database. The order of the checks is the specification:
     *
     *   1. everything delivered wins outright;
     *   2. once every line is settled the order is terminal, and it is partial
     *      only when both outcomes occurred;
     *   3. work still in flight outranks recoverable failures, because a
     *      failure that is being retried is not yet an outcome;
     *   4. what remains are recoverable lines, reported with the reason that
     *      describes all of them.
     *
     * @param  list<OrderItemStatus>  $itemStatuses
     */
    public static function deriveFrom(array $itemStatuses): self
    {
        if ($itemStatuses === []) {
            throw new DomainException('Cannot derive an order status without items.');
        }

        $has = static fn (callable $predicate): bool => array_any($itemStatuses, static fn (OrderItemStatus $s): bool => $predicate($s));
        $all = static fn (callable $predicate): bool => array_all($itemStatuses, static fn (OrderItemStatus $s): bool => $predicate($s));

        if ($all(static fn (OrderItemStatus $s): bool => $s === OrderItemStatus::Delivered)) {
            return self::Delivered;
        }

        if ($all(static fn (OrderItemStatus $s): bool => $s->isSettled())) {
            return $has(static fn (OrderItemStatus $s): bool => $s === OrderItemStatus::Delivered)
                ? self::PartiallyDelivered
                : self::Refunded;
        }

        if ($has(static fn (OrderItemStatus $s): bool => $s === OrderItemStatus::Delivering)) {
            return self::Delivering;
        }

        if ($has(static fn (OrderItemStatus $s): bool => $s === OrderItemStatus::Pending)) {
            return self::Paid;
        }

        return $all(static fn (OrderItemStatus $s): bool => $s !== OrderItemStatus::DeliveryFailed)
            ? self::OutOfStock
            : self::DeliveryFailed;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], strict: true);
    }

    /** No further money movement is possible. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::PartiallyDelivered, self::Refunded], strict: true);
    }

    public function isAwaitingDelivery(): bool
    {
        return in_array($this, [self::Paid, self::Delivering, self::OutOfStock, self::DeliveryFailed], strict: true);
    }

    public function isRecoverable(): bool
    {
        return in_array($this, [self::OutOfStock, self::DeliveryFailed], strict: true);
    }

    public function moneyReceived(): bool
    {
        return $this !== self::Created && $this !== self::PaymentFailed;
    }

    /**
     * Statuses of orders that may still need delivery or settlement work.
     *
     * @return list<string>
     */
    public static function unsettledValues(): array
    {
        return [
            self::Paid->value,
            self::Delivering->value,
            self::OutOfStock->value,
            self::DeliveryFailed->value,
        ];
    }
}
