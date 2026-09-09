<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Gateways;

/**
 * Outcome of a refund call.
 *
 * Classification is asymmetric for the same reason as SupplierOutcome: only a
 * definitive gateway rejection may be treated as "no money moved".
 */
enum RefundOutcome: string
{
    case Ok = 'ok';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
