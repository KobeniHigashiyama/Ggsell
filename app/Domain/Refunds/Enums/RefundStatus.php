<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Enums;

/**
 * State of one refund request.
 *
 * The asymmetry mirrors the supplier contract. Failed means the gateway
 * definitely did not move money, so asking again is safe. Unknown means it might
 * have, so the retry must reuse the same refund_request_id and can never be
 * turned into a second refund.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isResolved(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
