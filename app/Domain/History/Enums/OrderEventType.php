<?php

declare(strict_types=1);

namespace App\Domain\History\Enums;

/**
 * The facts the log records.
 *
 * Deliberately few. Each one either moves money or changes what the customer is
 * owed, which is exactly what a point-in-time answer has to reconstruct. Adding
 * chatter here would make the log larger without making it more truthful.
 */
enum OrderEventType: string
{
    case OrderCreated = 'order.created';
    case PaymentApplied = 'payment.applied';
    case PaymentFailed = 'payment.failed';
    case ItemDelivered = 'item.delivered';
    case ItemRefunded = 'item.refunded';

    /**
     * A line moved between non-terminal states.
     *
     * Without it a partly failed order is unreadable in history: every unsettled
     * line looks the same, and "which one was failing, and why" is exactly the
     * question a partial failure raises.
     */
    case ItemStatusChanged = 'item.status_changed';
    case OrderStatusChanged = 'order.status_changed';
    case SupplierViolation = 'supplier.violation';

    /** Events that move money, in the direction they move it. */
    public function moneyDirection(): int
    {
        return match ($this) {
            self::PaymentApplied => 1,
            self::ItemRefunded => -1,
            default => 0,
        };
    }
}
