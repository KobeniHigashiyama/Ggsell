<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * Order lifecycle.
 *
 * Allowed transitions are defined in one explicit table. Every status change
 * passes through canTransitionTo(), making repeated webhooks safe at the domain
 * level as well as at the event_id deduplication boundary.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case PaymentFailed = 'payment_failed';
    case OutOfStock = 'out_of_stock';
    case DeliveryFailed = 'delivery_failed';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Created->value => [self::Paid, self::PaymentFailed],
            self::Paid->value => [self::Delivering],
            self::Delivering->value => [self::Delivered, self::OutOfStock, self::DeliveryFailed],

            // Recoverable states may re-enter delivery. UNIQUE(order_id) on
            // deliveries prevents duplicates when a code was already issued.
            // A direct transition to delivered is also valid when a concurrent
            // run records delivery after another run reports a shortage.
            self::OutOfStock->value => [self::Delivering, self::Delivered],
            self::DeliveryFailed->value => [self::Delivering, self::Delivered],

            self::Delivered->value => [],

            // A later successful payment must not be ignored after a failure.
            // ApplyPaymentToOrder permits this only for a strictly newer event.
            self::PaymentFailed->value => [self::Paid],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], strict: true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered;
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
}
