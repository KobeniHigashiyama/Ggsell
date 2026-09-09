<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

/**
 * Ways a supplier can break its contract.
 *
 * These are recorded rather than merely logged because they drive two things:
 * automatic clean-up of the inventory the incident stranded, and a trust signal
 * that takes a persistently dishonest supplier out of the chain.
 */
enum ViolationKind: string
{
    /** The code is already assigned to another item in this system. */
    case DuplicateCode = 'duplicate_code';

    /** The code belongs to a different product than the one ordered. */
    case ForeignCode = 'foreign_code';

    /** The supplier reported failure but its registry shows an issued code. */
    case IssuedAfterError = 'issued_after_error';

    /** The supplier would not say which product the code belongs to. */
    case UnverifiedCode = 'unverified_code';
}
