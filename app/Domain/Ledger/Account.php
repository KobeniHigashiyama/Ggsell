<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * Minimal closed chart of accounts. Each operation affects exactly two accounts,
 * making transactions balanced by construction.
 */
enum Account: string
{
    /** Cash received from the acquirer. Asset. */
    case Cash = 'cash';

    /**
     * Obligation to deliver a product to a paying customer. Liability.
     *
     * Its balance must match paid but undelivered orders. Any exactly-once error,
     * whether missing or duplicate delivery, breaks this equality.
     */
    case CustomerLiability = 'customer_liability';

    /** Revenue recognized on delivery rather than payment. */
    case Revenue = 'revenue';
}
