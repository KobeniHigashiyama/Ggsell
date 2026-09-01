<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

/**
 * State of a single delivery attempt.
 *
 * Failed means the supplier definitely did not issue a code and fallback is safe.
 * Unknown means the supplier may have issued one but the response was lost. An
 * unknown attempt may only query the same supplier with the same request_id.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    case Unknown = 'unknown';

    public function isResolved(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function allowsFallback(): bool
    {
        return $this === self::Failed;
    }
}
