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
    public function несходящаяся_операция_отвергается_до_обращения_к_базе(): void
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
    public function операция_из_одной_стороны_отвергается(): void
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
