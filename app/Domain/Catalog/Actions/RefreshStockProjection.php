<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Delivery\Enums\SupplierId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Refreshes the stock projection.
 *
 * Suppliers are queried over HTTP, just like any other consumer of their
 * service. The core does not read a supplier schema directly even when it is
 * physically stored in the same database, preserving the service boundary.
 *
 * Projection drift is expected. A product may be shown after it runs out; the
 * delivery flow handles that as a recoverable out_of_stock outcome.
 */
final readonly class RefreshStockProjection
{
    public function __construct(
        private SyncStockFlag $syncStockFlag,
    ) {}

    public function handle(): int
    {
        $totals = [];

        foreach (SupplierId::chain() as $supplier) {
            $response = Http::baseUrl(config('ggsell.suppliers.base_url'))
                ->timeout(10)
                ->acceptJson()
                ->get("/suppliers/{$supplier->value}/stock");

            if (! $response->successful()) {
                continue;
            }

            foreach ((array) $response->json('stock', []) as $sku => $count) {
                $totals[$sku] = ($totals[$sku] ?? 0) + (int) $count;
            }
        }

        if ($totals === []) {
            return 0;
        }

        $now = now();

        foreach (array_chunk($totals, 500, preserve_keys: true) as $chunk) {
            DB::table('product_stock')->upsert(
                array_map(static fn (int $count, string $sku): array => [
                    'sku' => $sku,
                    'available_count' => $count,
                    'refreshed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk, array_keys($chunk)),
                ['sku'],
                ['available_count', 'refreshed_at', 'updated_at'],
            );
        }

        // Reset SKUs omitted by every supplier so removed products do not remain
        // available on the storefront indefinitely.
        DB::table('product_stock')
            ->whereNotIn('sku', array_keys($totals))
            ->update(['available_count' => 0, 'refreshed_at' => $now, 'updated_at' => $now]);

        $this->syncStockFlag->handle();

        return count($totals);
    }
}
