<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TalksToTheStack;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Stub\Supplier\ChaosMode;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reproduce task 2: a supplier that cannot be trusted.
 *
 * Supplier A is switched into a dishonest mode and supplier B is left honest, so
 * every customer can still be served while A misbehaves. The run then checks the
 * three properties that matter: nobody received somebody else's code, everybody
 * received exactly one working code, and the incident closed itself.
 */
class ChaosUntrustedCommand extends Command
{
    use TalksToTheStack;

    protected $signature = 'chaos:untrusted
        {mode=duplicate : duplicate | foreign_code | error_but_issued}
        {--orders=3 : how many customers to put through the dishonest supplier}
        {--sku=KEY-CS2-PRIME : SKU to order}
        {--wait=90 : seconds to wait for the orders to settle}';

    protected $description = 'Make a supplier break its contract and verify that no customer is harmed';

    public function handle(): int
    {
        $mode = ChaosMode::tryFrom((string) $this->argument('mode'));

        if ($mode === null || ! $mode->isDishonest()) {
            $this->components->error('Mode must be one of: duplicate, foreign_code, error_but_issued.');

            return self::FAILURE;
        }

        $this->prepareSuppliers($mode);

        $orders = $this->placeOrders((int) $this->option('orders'), (string) $this->option('sku'));

        if ($orders === []) {
            $this->components->error('No orders were created.');

            return self::FAILURE;
        }

        $delivered = $this->awaitAll($orders, (int) $this->option('wait'));

        // The scheduler runs this every minute; call it directly so the scenario
        // shows the automatic clean-up rather than waiting for the next tick.
        $this->components->info('Running the automatic discrepancy sweep');
        $this->call('ops:auto-resolve', ['--grace' => 0]);

        return $this->report($orders, $delivered, $mode);
    }

    /** @return list<array{order_id: string, amount_minor: int, currency: string}> */
    private function placeOrders(int $count, string $sku): array
    {
        $orders = [];

        for ($i = 0; $i < $count; $i++) {
            $order = $this->createOrder([['sku' => $sku, 'quantity' => 1]]);

            if ($order === null) {
                continue;
            }

            $this->payFor($order);
            $orders[] = $order;
        }

        $this->line(sprintf('  %d orders created and paid', count($orders)));

        return $orders;
    }

    /**
     * @param  list<array{order_id: string, amount_minor: int, currency: string}>  $orders
     * @return list<array<string, mixed>>
     */
    private function awaitAll(array $orders, int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $final = [];

        foreach ($orders as $order) {
            $remaining = max(1, (int) ($deadline - microtime(true)));

            $final[] = $this->awaitOrder(
                $order['order_id'],
                $remaining,
                static fn (array $body): bool => in_array(
                    $body['status'],
                    ['delivered', 'partially_delivered', 'refunded', 'out_of_stock', 'delivery_failed'],
                    strict: true,
                ),
            ) ?? [];
        }

        return $final;
    }

    /**
     * @param  list<array{order_id: string, amount_minor: int, currency: string}>  $orders
     * @param  list<array<string, mixed>>  $final
     */
    private function report(array $orders, array $final, ChaosMode $mode): int
    {
        $this->newLine();
        $this->table(
            ['Order', 'Status', 'Code'],
            array_map(static fn (array $order): array => [
                $order['order_id'] ?? '?',
                $order['status'] ?? '?',
                $order['code'] ?? '-',
            ], $final),
        );

        // Only the orders this run created, in the state they ended in.
        $delivered = array_values(array_filter(
            $final,
            static fn (array $order): bool => ($order['status'] ?? null) === 'delivered',
        ));
        $codes = array_filter(array_column($delivered, 'code'));
        $deliveredCodes = DB::table('deliveries')->count();
        $distinctCodes = DB::table('deliveries')->distinct()->count('code');
        $openViolations = DB::table('supplier_violations')->whereNull('resolved_at')->count();
        $violations = DB::table('supplier_violations')->count();
        $openOrphans = DB::table('orphaned_codes')->whereNull('resolved_at')->count();
        $money = (int) data_get(
            $this->get('/v1/ops/reconciliation?grace_seconds=30')['json'],
            'checks.money_conservation.count',
            1,
        );

        $checks = [
            ['Supplier misbehaved as instructed', "{$mode->value}: {$violations} violations", $violations > 0],
            ['One code never reached two customers', "{$distinctCodes}/{$deliveredCodes} distinct", $distinctCodes === $deliveredCodes],
            // Uniqueness alone passes on an empty list and on a half-served run,
            // so completeness is part of the same check: every order placed ended
            // delivered, every one of them carries a code, and no two are alike.
            [
                'Every customer holds exactly one code',
                count($codes).'/'.count($orders).' delivered, '.count(array_unique($codes)).' distinct',
                count($delivered) === count($orders)
                    && count($codes) === count($orders)
                    && count(array_unique($codes)) === count($orders),
            ],
            ['Discrepancies closed without an operator', $openViolations, $openViolations === 0],
            ['Stranded codes returned to the supplier', $openOrphans, $openOrphans === 0],
            ['Money still adds up', $money, $money === 0],
        ];

        $this->table(
            ['Check', 'Value', 'Result'],
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2] ? 'OK' : 'FAILED'], $checks),
        );

        $this->restoreSuppliers();

        return array_all($checks, static fn (array $row): bool => $row[2]) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Supplier A lies, supplier B does not.
     *
     * Leaving one honest supplier in the chain is the point: the customer must
     * still be served while the dishonest one is caught.
     */
    private function prepareSuppliers(ChaosMode $mode): void
    {
        Cache::put(SupplierIssueController::overrideKey('a'), $mode->value, now()->addMinutes(10));
        Cache::put(SupplierIssueController::overrideKey('b'), ChaosMode::Ok->value, now()->addMinutes(10));

        $breaker = app(CircuitBreaker::class);

        foreach (SupplierId::cases() as $supplier) {
            $breaker->recordSuccess($supplier);
        }

        $this->components->info("Supplier A switched to {$mode->value}; supplier B stays honest");
    }

    private function restoreSuppliers(): void
    {
        foreach (['a', 'b'] as $supplier) {
            Cache::forget(SupplierIssueController::overrideKey($supplier));
        }
    }
}
