<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\Account;
use App\Domain\Ledger\Actions\PostTransaction;
use App\Domain\Ledger\LedgerLine;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PostTransactionTest extends TestCase
{
    #[Test]
    public function unbalanced_transaction_is_rejected_before_database_access(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/imbalance/');

        // Balance validation happens before any database call, so an unbalanced
        // operation cannot reach persistence.
        (new PostTransaction)->handle(
            lines: [
                LedgerLine::debit(Account::Cash, 100_00),
                LedgerLine::credit(Account::Revenue, 90_00),
            ],
            currency: 'RUB',
            refType: 'test',
            refId: 'x',
        );
    }

    #[Test]
    public function single_sided_transaction_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        (new PostTransaction)->handle(
            lines: [LedgerLine::debit(Account::Cash, 100_00)],
            currency: 'RUB',
            refType: 'test',
            refId: 'y',
        );
    }
}
