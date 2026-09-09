<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\Enums\SupplierId;
use Illuminate\Support\Facades\DB;

/**
 * Per-supplier request budget, held in PostgreSQL.
 *
 * The counter deliberately mirrors the supplier's own: a fixed window aligned to
 * the wall-clock minute. An earlier version used a token bucket, which is the
 * nicer shape in isolation and the wrong one here — a bucket that starts full and
 * refills continuously allows its capacity *plus* a minute of refill inside one
 * of the supplier's windows, so a burst of thirty followed by paced traffic
 * pushed a supplier limited to thirty per minute over its limit and it started
 * answering 429. A limiter has to model the accounting of the party it is
 * protecting, not an idealized one.
 *
 * Every acquisition is one statement: the window roll and the increment are
 * decided inside it, and the row's own lock serializes concurrent workers, so
 * two of them cannot both take the last request of a window.
 *
 * The instant comes from the application rather than the database clock. Workers
 * already agree on time closely enough for a per-minute budget, and it keeps the
 * limiter exercisable: a test can move the clock instead of waiting a minute.
 */
final readonly class SupplierRateLimiter
{
    /**
     * Spends one request from the supplier's allowance for the current window.
     *
     * @return bool False when the window is exhausted, in which case the caller
     *              must wait rather than send.
     */
    public function tryAcquire(SupplierId $supplier): bool
    {
        $now = now();

        $rows = DB::update(<<<'SQL'
            INSERT INTO supplier_rate_limits (supplier, window_start, used, limit_per_window, updated_at)
            VALUES (?, date_trunc('minute', ?::timestamptz), 1, ?, ?)
            ON CONFLICT (supplier) DO UPDATE
            SET used = CASE
                    WHEN supplier_rate_limits.window_start < EXCLUDED.window_start THEN 1
                    ELSE supplier_rate_limits.used + 1
                END,
                window_start = GREATEST(supplier_rate_limits.window_start, EXCLUDED.window_start),
                limit_per_window = EXCLUDED.limit_per_window,
                updated_at = EXCLUDED.updated_at
            WHERE supplier_rate_limits.window_start < EXCLUDED.window_start
               OR supplier_rate_limits.used < EXCLUDED.limit_per_window
        SQL, [$supplier->value, $now, $this->limitPerWindow($supplier), $now]);

        return $rows > 0;
    }

    /**
     * Spends one request on background work, but only while a reserve remains.
     *
     * Audits and code returns talk to the same supplier as deliveries and come out
     * of the same allowance. Left equal, a large incident would spend a whole
     * minute's budget on questions while paying customers waited, which is the
     * queue-priority decision applied to the other shared resource.
     */
    public function tryAcquireForBackgroundWork(SupplierId $supplier): bool
    {
        return $this->remaining($supplier) > $this->reserveFor($supplier)
            && $this->tryAcquire($supplier);
    }

    /**
     * Requests held back for deliveries, never spent on background work.
     *
     * Capped one below the limit so a reserve can never consume the entire
     * window: at a limit of one request per minute a half share rounds up to the
     * whole allowance, and audits and code returns would stop running at all,
     * even with nothing to deliver.
     */
    private function reserveFor(SupplierId $supplier): int
    {
        $limit = $this->limitPerWindow($supplier);
        $share = (float) config('ggsell.suppliers.delivery_reserve_share', 0.5);

        return min((int) ceil($limit * $share), $limit - 1);
    }

    /** True when at least one supplier in the chain can be called right now. */
    public function hasCapacityForAnySupplier(): bool
    {
        foreach (SupplierId::chain() as $supplier) {
            if ($this->remaining($supplier) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Requests the supplier will still accept in the current window. */
    public function remaining(SupplierId $supplier): int
    {
        $limit = $this->limitPerWindow($supplier);

        $row = DB::selectOne(<<<'SQL'
            SELECT used, window_start < date_trunc('minute', ?::timestamptz) AS stale
            FROM supplier_rate_limits
            WHERE supplier = ?
        SQL, [now(), $supplier->value]);

        if ($row === null || $row->stale) {
            // No window has started yet, or the stored one has expired: the next
            // request opens a fresh one with the full allowance.
            return $limit;
        }

        return max(0, $limit - (int) $row->used);
    }

    /**
     * Allowance of every supplier, for the progress endpoint.
     *
     * @return list<array{supplier: string, remaining: int, per_minute: int}>
     */
    public function snapshot(): array
    {
        return array_map(fn (SupplierId $supplier): array => [
            'supplier' => $supplier->value,
            'remaining' => $this->remaining($supplier),
            'per_minute' => $this->limitPerWindow($supplier),
        ], SupplierId::chain());
    }

    private function limitPerWindow(SupplierId $supplier): int
    {
        return (int) config("ggsell.suppliers.rate_limit.{$supplier->value}", 30);
    }
}
