<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\SyncStockFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generate the large catalog required by stage 5.
 *
 * Data is synthetic but reasonably distributed: types are limited, prices vary,
 * and sort_rank is unique so keyset pagination is tested on realistic input.
 */
class SeedLoadCatalogCommand extends Command
{
    protected $signature = 'catalog:seed-load
        {--skus=50000 : number of SKUs to generate}
        {--keys-per-sku=4 : keys per SKU for each supplier}
        {--chunk=2000}';

    protected $description = 'Seed enough catalog and supplier data to inspect query plans';

    private const TYPES = ['topup', 'key', 'subscription', 'giftcard'];

    public function handle(): int
    {
        $total = (int) $this->option('skus');
        $keysPerSku = (int) $this->option('keys-per-sku');
        $chunk = (int) $this->option('chunk');
        $now = now();

        $this->components->info("Generating {$total} SKUs...");
        $bar = $this->output->createProgressBar($total);

        for ($offset = 0; $offset < $total; $offset += $chunk) {
            $products = [];
            $stock = [];
            $keys = [];
            $size = min($chunk, $total - $offset);

            for ($i = 0; $i < $size; $i++) {
                $n = $offset + $i;
                $sku = sprintf('LOAD-%06d', $n);
                $type = self::TYPES[$n % count(self::TYPES)];

                $products[] = [
                    'sku' => $sku,
                    'name' => "Load-test product {$n}",
                    'type' => $type,
                    'price_minor' => (100 + ($n * 37) % 490000),
                    'currency' => 'RUB',
                    'image' => "assets/load/{$type}.png",
                    // Disable part of the catalog so the partial index is tested
                    // on data where it can exclude rows.
                    'is_active' => $n % 20 !== 0,
                    'sort_rank' => $n,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $stock[] = [
                    'sku' => $sku,
                    'available_count' => 0,
                    'issued_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach (['a', 'b'] as $supplier) {
                    for ($k = 0; $k < $keysPerSku; $k++) {
                        $keys[] = [
                            'supplier' => $supplier,
                            'sku' => $sku,
                            'code' => sprintf('LD%s%06d%04d', strtoupper($supplier), $n, $k),
                            'status' => 'available',
                        ];
                    }
                }
            }

            DB::table('products')->insertOrIgnore($products);
            DB::table('product_stock')->insertOrIgnore($stock);

            foreach (array_chunk($keys, 5000) as $keyChunk) {
                DB::table('stub.supplier_keys')->insertOrIgnore($keyChunk);
            }

            $bar->advance($size);
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->task('Refreshing stock projection', function (): void {
            DB::statement(<<<'SQL'
                UPDATE product_stock ps
                SET available_count = COALESCE(agg.available, 0), refreshed_at = now(), updated_at = now()
                FROM (
                    SELECT sku, COUNT(*) AS available
                    FROM stub.supplier_keys
                    WHERE status = 'available'
                    GROUP BY sku
                ) agg
                WHERE ps.sku = agg.sku
            SQL);

            app(SyncStockFlag::class)->handle();
        });

        // Fresh statistics are required for meaningful planner estimates. Use
        // VACUUM as well as ANALYZE so the visibility map allows an Index Only
        // Scan to reproduce Heap Fetches: 0 immediately after seeding.
        $this->components->task('VACUUM ANALYZE', function (): void {
            DB::statement('VACUUM ANALYZE products');
            DB::statement('VACUUM ANALYZE product_stock');
            DB::statement('VACUUM ANALYZE stub.supplier_keys');
        });

        $this->components->info(sprintf(
            'Catalog: %s SKUs, keys: %s.',
            number_format((float) DB::table('products')->count(), 0, '.', ' '),
            number_format((float) DB::table('stub.supplier_keys')->count(), 0, '.', ' '),
        ));

        return self::SUCCESS;
    }
}
