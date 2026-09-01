<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Actions;

use App\Domain\Ledger\LedgerLine;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records one business transaction in the ledger.
 *
 * This class enforces two properties:
 *
 * 1. An unbalanced set of entries is rejected before any database write.
 *
 * 2. (ref_type, ref_id, account) provides idempotency so recovery paths can post
 *    the same transaction again without duplicating money.
 *
 * The caller must invoke this inside its transaction so ledger and order state
 * change atomically.
 */
final readonly class PostTransaction
{
    /**
     * @param  list<LedgerLine>  $lines
     * @return bool True when entries were written; false when already recorded.
     */
    public function handle(
        array $lines,
        string $currency,
        string $refType,
        string $refId,
        ?int $orderId = null,
    ): bool {
        if (count($lines) < 2) {
            throw new DomainException('A ledger transaction needs at least two lines.');
        }

        $imbalance = array_sum(array_map(static fn (LedgerLine $line): int => $line->amountMinor, $lines));

        if ($imbalance !== 0) {
            throw new DomainException(sprintf(
                'Refusing to post an unbalanced transaction %s:%s, imbalance is %s.',
                $refType,
                $refId,
                Money::format($imbalance, $currency),
            ));
        }

        $transactionId = (string) Str::uuid();
        $now = now();

        $rows = array_map(static fn (LedgerLine $line): array => [
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'account' => $line->account->value,
            'direction' => $line->direction(),
            'amount_minor' => $line->amountMinor,
            'currency' => $currency,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'created_at' => $now,
        ], $lines);

        // insertOrIgnore delegates concurrent deduplication to the unique index.
        $inserted = DB::table('ledger_entries')->insertOrIgnore($rows);

        if ($inserted === 0) {
            return false;
        }

        // A partial insert would leave the transaction unbalanced and must fail.
        if ($inserted !== count($rows)) {
            throw new DomainException(sprintf(
                'Partially posted transaction %s:%s (%d of %d lines).',
                $refType, $refId, $inserted, count($rows),
            ));
        }

        return true;
    }
}
