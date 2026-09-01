<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * One side of a ledger transaction. Debits are positive; credits are negative.
 */
final readonly class LedgerLine
{
    private function __construct(
        public Account $account,
        public int $amountMinor,
    ) {}

    public static function debit(Account $account, int $amountMinor): self
    {
        return new self($account, abs($amountMinor));
    }

    public static function credit(Account $account, int $amountMinor): self
    {
        return new self($account, -abs($amountMinor));
    }

    public function direction(): string
    {
        return $this->amountMinor > 0 ? 'debit' : 'credit';
    }
}
