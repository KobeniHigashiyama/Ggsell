<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

/**
 * Outcome of trying to hand a supplier code to an order item.
 *
 * CodeTaken is the interesting one: the code came back from the supplier but it
 * already belongs to another item, which means the supplier issued a duplicate.
 * The caller must not treat that as a delivery, and must not treat it as a plain
 * failure either, because the customer is still owed a code.
 */
enum DeliveryCommitResult
{
    case Committed;

    /** Another run delivered this item first; nothing further is owed. */
    case AlreadyDelivered;

    /** The code belongs to a different item and cannot be delivered here. */
    case CodeTaken;

    /**
     * A refund for this item has started, so delivering it would hand over both
     * the product and the money. The supplier did nothing wrong here.
     */
    case RefusedRefundInFlight;

    public function isDelivered(): bool
    {
        return $this === self::Committed || $this === self::AlreadyDelivered;
    }

    /**
     * True when the code was refused because of this system's own state rather
     * than anything the supplier did, so it must not be recorded as a violation.
     */
    public function isSupplierAtFault(): bool
    {
        return $this === self::CodeTaken;
    }
}
