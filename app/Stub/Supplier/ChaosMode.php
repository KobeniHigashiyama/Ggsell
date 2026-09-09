<?php

declare(strict_types=1);

namespace App\Stub\Supplier;

/**
 * How the supplier stub misbehaves.
 *
 * The first four model an honest supplier having a bad day. The last three model
 * stage 2's dishonest one: it can hand out the same code twice, hand over a code
 * that belongs to a different product, or claim failure after it already issued.
 */
enum ChaosMode: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Timeout = 'timeout';
    case OutOfStock = 'out_of_stock';

    /** Returns a code it has already given to somebody else. */
    case Duplicate = 'duplicate';

    /** Returns a code from another product's pool. */
    case ForeignCode = 'foreign_code';

    /** Issues the code, then reports a definitive failure. */
    case ErrorButIssued = 'error_but_issued';

    /** True when the mode makes the supplier lie rather than merely fail. */
    public function isDishonest(): bool
    {
        return in_array($this, [self::Duplicate, self::ForeignCode, self::ErrorButIssued], strict: true);
    }
}
