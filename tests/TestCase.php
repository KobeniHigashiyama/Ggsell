<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Catalog\Actions\SyncStockFlag;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function seedCatalog(): void
    {
        $this->seed(CatalogSeeder::class);
    }

    /**
     * Seeds a specific supplier with an exact number of keys.
     *
     * Tests control stock explicitly because empty-stock scenarios require exact
     * per-supplier inventory.
     */
    protected function seedSupplierKeys(string $supplier, string $sku, int $count, string $prefix = 'TEST'): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'supplier' => $supplier,
                'sku' => $sku,
                'code' => sprintf('%s-%s-%s-%04d', $prefix, strtoupper($supplier), substr(md5($sku), 0, 4), $i),
                'status' => 'available',
            ];
        }

        if ($rows !== []) {
            DB::table('stub.supplier_keys')->insert($rows);
        }

        DB::table('product_stock')->where('sku', $sku)->update([
            'available_count' => DB::raw('available_count + '.$count),
        ]);

        app(SyncStockFlag::class)->handle($sku);
    }

    protected function assertLedgerBalanced(): void
    {
        $unbalanced = DB::table('ledger_entries')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->count();

        $this->assertSame(0, $unbalanced, 'The ledger is unbalanced.');
    }
}
