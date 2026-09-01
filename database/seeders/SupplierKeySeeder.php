<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Actions\SyncStockFlag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds supplier key pools.
 *
 * Supplier A receives the provided keys, followed by deterministic generated
 * keys for test capacity. Supplier B uses a disjoint pool so one code cannot be
 * assigned through two suppliers.
 */
class SupplierKeySeeder extends Seeder
{
    private const PROVIDED_KEYS = [
        'LFXC-TNCS-BPCD', 'P3EI-W8UO-9B4K', 'FEL3-GUXN-TCCH', 'YPLV-QK2Z-IUS5', '0K9E-P1FR-BY1U',
        '5LZV-UQ48-RXCZ', 'X93K-NYAQ-GEC1', 'EIO5-CQT5-35KO', 'M58F-GIIR-VJAP', 'NU8Y-SWYB-6252',
        'OODW-CCHF-MBAF', 'DNA5-WFJM-NE49', 'QRDD-MJ3F-A8TF', 'TAT9-5ZJN-G1T2', 'LI39-4330-ISMB',
        'BKJY-8Q79-8NHI', 'HHW6-4RX2-DX62', '1RG2-L28O-O80G', 'EF63-F39X-MTEA', '8XS7-P53H-JKIV',
        'JPE6-MQV6-P7ST', 'SAPG-A2GR-0ULS', 'T2DU-IJ1S-U16P', 'WSSY-QTR7-Z57J', 'U74E-EPCI-CY26',
        'FZXF-58H8-OR93', 'FPSM-HLZA-TPAL', 'WSC9-28DJ-B2JE', 'P63J-F7UZ-DCYP', 'C7W2-D4C5-QMT7',
        'JESI-DFBH-LK1K', 'SGMA-JA0T-GR7D', '3PR4-OSY9-M3ZW', 'OMBE-C0JF-D45Y', 'KIKQ-FQJ8-9TI8',
        'LMAN-RSHS-AJDO', 'BAKI-VT1X-Z5OL', '9F0X-B46W-03FS', 'S423-V6YY-IBEM', 'D4UW-WYRA-20ST',
        'XC0J-CJ0H-09RN', 'RY1W-XCFJ-0KUA', 'CJYY-YKSQ-QE6H', '97AQ-38QJ-H8HU', 'FS8E-3S5Z-I6RA',
        'ARQK-FML4-A14E', '7Z6K-NO9V-MPJB', 'D4K7-IJSG-N853', 'W67T-ZB0Q-1XKB', '7EQM-K09J-XKUO',
    ];

    public function run(int $perSkuPerSupplier = 40): void
    {
        // Seed only the base catalog; catalog:seed-load provisions its own SKUs.
        $skus = array_values(array_intersect(
            CatalogSeeder::skus(),
            DB::table('products')->pluck('sku')->all(),
        ));

        if ($skus === []) {
            $this->command?->warn('Базовый каталог пуст, пул ключей не наполнен.');

            return;
        }

        $rows = [];

        foreach (self::PROVIDED_KEYS as $i => $code) {
            $rows[] = ['supplier' => 'a', 'sku' => $skus[$i % count($skus)], 'code' => $code, 'status' => 'available'];
        }

        // Deterministic generation keeps scenario reproduction independent
        // of random data.
        foreach (['a', 'b'] as $supplier) {
            foreach ($skus as $skuIndex => $sku) {
                for ($n = 0; $n < $perSkuPerSupplier; $n++) {
                    $rows[] = [
                        'supplier' => $supplier,
                        'sku' => $sku,
                        'code' => $this->generateCode($supplier, $skuIndex, $n),
                        'status' => 'available',
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            // Repeated seeding must neither fail nor restore already issued keys.
            DB::table('stub.supplier_keys')->insertOrIgnore($chunk);
        }

        $this->refreshProjection($skus);

        $this->command?->info('Пулы поставщиков: '.count($rows).' ключей.');
    }

    /**
     * This fixture calculates the stock projection directly. At runtime,
     * RefreshStockProjection updates it over the supplier HTTP boundary.
     *
     * @param  list<string>  $skus
     */
    private function refreshProjection(array $skus): void
    {
        $array = '{'.implode(',', $skus).'}';

        DB::statement(<<<'SQL'
            UPDATE product_stock ps
            SET available_count = COALESCE(agg.available, 0),
                refreshed_at = now(),
                updated_at = now()
            FROM (
                SELECT p.sku, COUNT(k.id) AS available
                FROM products p
                LEFT JOIN stub.supplier_keys k ON k.sku = p.sku AND k.status = 'available'
                WHERE p.sku = ANY(?)
                GROUP BY p.sku
            ) agg
            WHERE ps.sku = agg.sku
        SQL, [$array]);

        app(SyncStockFlag::class)->handle();
    }

    private function generateCode(string $supplier, int $skuIndex, int $n): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $seed = crc32(sprintf('%s|%d|%d', $supplier, $skuIndex, $n));
        $out = '';

        for ($i = 0; $i < 12; $i++) {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            $out .= $alphabet[$seed % strlen($alphabet)];
        }

        return implode('-', str_split($out, 4));
    }
}
