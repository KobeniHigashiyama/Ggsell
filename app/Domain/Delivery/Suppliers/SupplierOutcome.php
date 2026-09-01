<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

/**
 * Outcome of a supplier request.
 *
 * Classification is intentionally asymmetric: Rejected is used only when the
 * supplier definitely did not issue a code. Every uncertain result is Unknown.
 *
 * Misclassifying a result as Rejected can trigger fallback and issue a second key
 * for one payment, so uncertain outcomes always remain Unknown.
 */
enum SupplierOutcome: string
{
    case Ok = 'ok';

    case Rejected = 'rejected';

    case Unknown = 'unknown';
}
