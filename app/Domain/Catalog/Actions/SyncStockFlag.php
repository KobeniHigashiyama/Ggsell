<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Synchronizes the products availability flag with the stock counter.
 *
 * Only rows whose flag changes are written. The flag is part of the storefront
 * covering index, so updating it on every delivery would add index writes back
 * to the hot path that the separate counter is intended to avoid.
 */
final readonly class SyncStockFlag
{
    /**
     * @param  string|null  $sku  Limit synchronization to one product; null means the full catalog.
     * @return int Number of flags changed.
     */
    public function handle(?string $sku = null): int
    {
        $sql = <<<'SQL'
            UPDATE products p
            SET in_stock = (s.available_count > 0), updated_at = now()
            FROM product_stock s
            WHERE s.sku = p.sku
              AND p.in_stock <> (s.available_count > 0)
        SQL;

        if ($sku !== null) {
            return DB::update($sql.' AND p.sku = ?', [$sku]);
        }

        return DB::update($sql);
    }
}
