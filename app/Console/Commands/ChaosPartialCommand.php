<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TalksToTheStack;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Domain\Ordering\Models\Order;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reproduce task 1: an order of several products where part cannot be delivered.
 *
 * One line is ordered from a product both suppliers can serve and one from a
 * product whose pools are emptied first, so the outcome is deterministic rather
 * than a matter of luck. What is delivered stays delivered, the rest is
 * refunded, and the run ends by checking that the money adds up.
 */
class ChaosPartialCommand extends Command
{
    use TalksToTheStack;

    protected $signature = 'chaos:partial
        {--deliverable=KEY-CS2-PRIME : SKU both suppliers can serve}
        {--undeliverable=KEY-EFT : SKU whose pools are emptied for this run}
        {--wait=90 : seconds to wait for the order to reach a terminal state}';

    protected $description = 'Create a multi-product order where one line cannot be delivered, and watch it settle honestly';

    public function handle(): int
    {
        $deliverable = (string) $this->option('deliverable');
        $undeliverable = (string) $this->option('undeliverable');

        $this->pinSuppliersToOk();
        $emptied = $this->emptyPoolsFor($undeliverable);
        $this->line("  Emptied {$emptied} supplier keys for {$undeliverable}");

        $order = $this->createOrder([
            ['sku' => $deliverable, 'quantity' => 1],
            ['sku' => $undeliverable, 'quantity' => 1],
        ]);

        if ($order === null) {
            $this->components->error('Failed to create the order.');

            return self::FAILURE;
        }

        $this->components->info("Order {$order['order_id']}: one line deliverable, one not");
        $this->payFor($order);

        // Settlement runs on the scheduler once a minute; call it directly so the
        // scenario finishes in seconds rather than waiting for the next tick.
        $final = $this->awaitOrder(
            $order['order_id'],
            (int) $this->option('wait'),
            fn (array $body): bool => $this->settleAndCheck($body),
        );

        return $this->report($order['order_id'], $final);
    }

    /**
     * Drives settlement while polling, so a line that has failed delivery is
     * refunded without waiting for the next scheduled sweep.
     */
    private function settleAndCheck(array $order): bool
    {
        if (in_array($order['status'], ['delivered', 'partially_delivered', 'refunded'], strict: true)) {
            return true;
        }

        if (in_array($order['status'], ['out_of_stock', 'delivery_failed'], strict: true)) {
            $this->callSilently('orders:settle', ['--give-up-after' => 0]);
        }

        return false;
    }

    private function report(string $orderId, ?array $order): int
    {
        if ($order === null) {
            $this->components->error('The order never reached a terminal state.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Line', 'SKU', 'Status', 'Code'],
            array_map(static fn (array $item): array => [
                $item['position'],
                $item['sku'],
                $item['status'],
                $item['code'] ?? '-',
            ], $order['items']),
        );

        $internalId = (int) Order::query()->where('public_id', $orderId)->value('id');

        $delivered = (int) DB::table('order_items')->where('order_id', $internalId)->where('status', 'delivered')->sum('amount_minor');
        $refunded = (int) DB::table('order_items')->where('order_id', $internalId)->where('status', 'refunded')->sum('amount_minor');
        $liability = -(int) DB::table('ledger_entries')
            ->where('order_id', $internalId)
            ->where('account', 'customer_liability')
            ->sum('amount_minor');

        $checks = [
            ['Order reached a terminal state', $order['status'], $order['status'] === 'partially_delivered'],
            ['Paid equals delivered plus refunded', "{$order['amount_minor']} = {$delivered} + {$refunded}", $order['amount_minor'] === $delivered + $refunded],
            ['Nothing is still owed on this order', $liability, $liability === 0],
            ['Money adds up system wide', ...$this->moneyConservationCheck()],
        ];

        $this->table(
            ['Check', 'Value', 'Result'],
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2] ? 'OK' : 'FAILED'], $checks),
        );

        return array_all($checks, static fn (array $row): bool => $row[2]) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: string, 1: bool} */
    private function moneyConservationCheck(): array
    {
        $check = data_get($this->get('/v1/ops/reconciliation?grace_seconds=30')['json'], 'checks.money_conservation');

        return [(string) data_get($check, 'count', 'n/a'), (int) data_get($check, 'count', 1) === 0];
    }

    /** Removes every available key for a SKU so its line genuinely cannot be served. */
    private function emptyPoolsFor(string $sku): int
    {
        $emptied = DB::table('stub.supplier_keys')
            ->where('sku', $sku)
            ->where('status', 'available')
            ->update(['status' => 'revoked', 'returned_at' => now()]);

        DB::table('product_stock')->where('sku', $sku)->update(['available_count' => 0, 'updated_at' => now()]);

        return $emptied;
    }

    /**
     * Both stubs answer honestly for this scenario: the subject is a partial
     * failure, not supplier misbehavior.
     */
    private function pinSuppliersToOk(): void
    {
        foreach (array_keys((array) config('ggsell.stubs')) as $supplier) {
            Cache::put(SupplierIssueController::overrideKey($supplier), 'ok', now()->addMinutes(10));
        }

        $breaker = app(CircuitBreaker::class);

        foreach (SupplierId::cases() as $supplier) {
            $breaker->recordSuccess($supplier);
        }
    }
}
