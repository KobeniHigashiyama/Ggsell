<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Lifecycle of one deliverable unit.
 *
 * Stage 2 moves the real state machine here. An order can no longer transition
 * on its own, because it may hold items in several different states at once; its
 * status is derived from this enum by RecalculateOrderStatus.
 *
 * Two states are terminal and they are the only two that settle money:
 * Delivered keeps the payment, Refunded returns it. Everything else still owes
 * the customer either a code or a refund.
 */
enum OrderItemStatus: string
{
    /** Awaiting payment, or paid and queued for delivery. */
    case Pending = 'pending';

    case Delivering = 'delivering';

    /** Terminal. The customer holds a code and the money is earned. */
    case Delivered = 'delivered';

    /** Recoverable: supplier inventory was empty, replenishment may fix it. */
    case OutOfStock = 'out_of_stock';

    /** Recoverable: delivery failed or its outcome is still unresolved. */
    case DeliveryFailed = 'delivery_failed';

    /** Terminal. The money went back to the customer. */
    case Refunded = 'refunded';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            // A refund straight from pending covers an order abandoned before any
            // supplier call, for example when the whole order is cancelled.
            self::Pending->value => [self::Delivering, self::Refunded],

            // Refunded is reachable from delivering only through settlement, and
            // only for a line whose run budget is spent and that holds no open
            // supplier attempt. Without that edge a process killed on its last
            // allowed run leaves an order that can never reach a terminal state.
            self::Delivering->value => [self::Delivered, self::OutOfStock, self::DeliveryFailed, self::Refunded],

            // Recoverable states may re-enter delivery. A direct move to delivered
            // is also valid when a concurrent run commits a code after this run
            // already reported a shortage.
            self::OutOfStock->value => [self::Delivering, self::Delivered, self::Refunded],
            self::DeliveryFailed->value => [self::Delivering, self::Delivered, self::Refunded],

            self::Delivered->value => [],
            self::Refunded->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], strict: true);
    }

    /** Money is decided and no further work is possible. */
    public function isSettled(): bool
    {
        return $this === self::Delivered || $this === self::Refunded;
    }

    public function isRecoverable(): bool
    {
        return $this === self::OutOfStock || $this === self::DeliveryFailed;
    }

    /** Delivery work is either queued or running. */
    public function isInFlight(): bool
    {
        return $this === self::Pending || $this === self::Delivering;
    }
}
