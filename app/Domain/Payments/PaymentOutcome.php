<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * Describes how a webhook was handled. Stored in payment_events.outcome as the
 * primary audit signal for duplicate and concurrent delivery analysis.
 */
enum PaymentOutcome: string
{
    /** The event was applied and changed the order status. */
    case Applied = 'applied';

    /** The same event ID was already received; no action is required. */
    case Duplicate = 'duplicate';

    /** The order does not exist yet; the stored event remains pending. */
    case PendingOrder = 'pending_order';

    /** The order is already in a state this event does not change. */
    case NoOp = 'no_op';

    /** The event is older than the applied event and is ignored as stale. */
    case Stale = 'stale';

    /** The amount or currency differs from the order and needs manual review. */
    case Mismatch = 'mismatch';
}
