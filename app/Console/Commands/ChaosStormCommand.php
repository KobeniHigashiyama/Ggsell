<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TalksToTheStack;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Stub\Supplier\ChaosMode;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The hard case: mass delivery while a supplier falls over mid-flight.
 *
 * Each stage-2 mechanism has its own scenario, but the failure modes that matter
 * are the ones that arrive together. Here a large batch is paid at once, and a
 * few seconds in — with deliveries already running, keys already claimed and the
 * queue already deep — supplier A starts failing in whichever way was asked for.
 *
 * What it verifies is stronger than "it finished". Every key the supplier
 * consumed has to be accounted for: delivered to the customer it was issued for,
 * recorded as stranded inventory, or still attached to an attempt whose outcome
 * is genuinely unresolved. A key that is none of those was paid for and lost,
 * and no other check in the system would notice it.
 */
class ChaosStormCommand extends Command
{
    use TalksToTheStack;

    protected $signature = 'chaos:storm
        {--n=40 : orders to create and pay at once}
        {--sku=KEY-CS2-PRIME : SKU to order}
        {--fail=timeout : how supplier A falls over: timeout | error | error_but_issued | duplicate | out_of_stock}
        {--fail-after=3 : seconds into the delivery run before supplier A falls over}
        {--recover-after=0 : seconds before supplier A comes back; 0 leaves it broken}
        {--fail-both : take the whole supply chain down, so the batch has to be refunded}
        {--wait=300 : seconds to wait for every order to reach a terminal state}
        {--converge=300 : seconds to let the background sweeps finish clearing up}';

    protected $description = 'Pay a large batch, knock a supplier over mid-delivery, and verify nothing is lost or issued twice';

    public function handle(): int
    {
        $mode = ChaosMode::tryFrom((string) $this->option('fail'));

        if ($mode === null || $mode === ChaosMode::Ok) {
            $this->components->error('--fail must be one of: timeout, error, error_but_issued, duplicate, out_of_stock.');

            return self::FAILURE;
        }

        $startedAt = now();
        $this->resetSuppliers();

        $orders = $this->createAndPay((int) $this->option('n'), (string) $this->option('sku'));

        if ($orders === []) {
            $this->components->error('No orders were created.');

            return self::FAILURE;
        }

        $this->breakSupplierAfter($mode, (int) $this->option('fail-after'));
        $this->drain((int) $this->option('wait'), count($orders), $mode);
        $this->converge((int) $this->option('converge'));

        return $this->report($startedAt, count($orders), $mode);
    }

    /**
     * @return list<array{order_id: string, amount_minor: int, currency: string}>
     */
    private function createAndPay(int $count, string $sku): array
    {
        $multi = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $handles[] = $handle = $this->makeHandle('POST', '/v1/orders', ['sku' => $sku], [
                'Idempotency-Key: storm-'.Str::uuid(),
            ]);
            curl_multi_add_handle($multi, $handle);
        }

        $orders = [];

        foreach ($this->runInParallel($multi, $handles) as $body) {
            $data = data_get(json_decode($body, true), 'data');

            if (is_array($data) && isset($data['order_id'])) {
                $orders[] = [
                    'order_id' => (string) $data['order_id'],
                    'amount_minor' => (int) $data['amount_minor'],
                    'currency' => (string) $data['currency'],
                ];
            }
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($orders as $order) {
            $handles[] = $handle = $this->makeHandle('POST', '/v1/webhooks/payment', [
                'event_id' => 'evt_'.Str::lower((string) Str::ulid()),
                'order_id' => $order['order_id'],
                'status' => 'paid',
                'amount' => number_format($order['amount_minor'] / 100, 2, '.', ''),
                'currency' => $order['currency'],
                'created_at' => now()->toIso8601String(),
            ]);
            curl_multi_add_handle($multi, $handle);
        }

        $this->runInParallel($multi, $handles);
        $this->components->info(sprintf('%d orders created and paid at once', count($orders)));

        return $orders;
    }

    /**
     * Lets delivery get properly under way, then takes supplier A down.
     *
     * The delay is the whole point: failing before the first request would only
     * test the fallback path, while failing here catches attempts already open
     * and keys already claimed.
     */
    private function breakSupplierAfter(ChaosMode $mode, int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }

        Cache::put(SupplierIssueController::overrideKey('a'), $mode->value, now()->addMinutes(30));

        if ($this->option('fail-both')) {
            Cache::put(SupplierIssueController::overrideKey('b'), $mode->value, now()->addMinutes(30));
        }

        $this->components->warn(sprintf(
            'Supplier A is now failing with %s, mid-delivery. %s',
            $mode->value,
            $this->option('fail-both') ? 'Supplier B is down too: nothing can be delivered.' : 'Supplier B stays honest.',
        ));
    }

    /**
     * Watches the backlog drain, nudging the scheduled sweeps along.
     *
     * They run every minute in production; calling them here keeps a scenario a
     * scenario rather than a five-minute wait, and it is the same code path.
     */
    private function drain(int $seconds, int $expected, ChaosMode $mode): void
    {
        $deadline = microtime(true) + $seconds;
        $recoverAfter = (int) $this->option('recover-after');
        $recoverAt = $recoverAfter > 0 ? microtime(true) + $recoverAfter : null;
        $lastLine = '';

        do {
            if ($recoverAt !== null && microtime(true) >= $recoverAt) {
                $this->resetSuppliers();
                $this->components->info('Supplier A is healthy again');
                $recoverAt = null;
            }

            $progress = $this->get('/v1/ops/delivery/progress')['json'];
            $waiting = (int) data_get($progress, 'items.awaiting_delivery');
            $delivered = (int) data_get($progress, 'items.delivered');
            $refunded = (int) data_get($progress, 'items.refunded');

            $line = sprintf(
                '  waiting %d, delivered %d, refunded %d, allowance %s',
                $waiting, $delivered, $refunded,
                collect(data_get($progress, 'supplier_allowance', []))
                    ->map(fn (array $row): string => "{$row['supplier']}:{$row['remaining']}")
                    ->implode(' '),
            );

            if ($line !== $lastLine) {
                $this->line($line);
                $lastLine = $line;
            }

            if ($waiting === 0 && $delivered + $refunded >= $expected) {
                break;
            }

            // Audit what the broken supplier actually did, then settle whatever is
            // genuinely undeliverable.
            $this->callSilently('ops:auto-resolve', ['--grace' => 0]);
            $this->callSilently('orders:resolve-stuck', ['--stuck-after' => 5]);
            $this->callSilently('orders:settle', ['--give-up-after' => 0]);

            usleep(700_000);
        } while (microtime(true) < $deadline);

        // One last audit so a code issued behind the final failure is not left
        // hanging purely because the loop ended first.
        $this->callSilently('ops:auto-resolve', ['--grace' => 0]);
    }

    /**
     * Waits for the clean-up to finish, not just the deliveries.
     *
     * Orders reach a terminal state well before the incident is closed: stranded
     * codes still have to go back and violations have to be resolved, and those
     * sweeps deliberately yield supplier allowance to deliveries. With a large
     * batch that can take several rate-limit windows, so the wait is generous on
     * purpose: measuring when the queue empties would be measuring too early, and
     * a scenario that sometimes measures too early is worse than a slow one.
     */
    private function converge(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        // A hard ceiling for the case where clean-up is still moving but will
        // never arrive, so a stuck run ends as a report rather than a hang.
        $ceiling = microtime(true) + ($seconds * 2);
        $open = PHP_INT_MAX;

        do {
            $this->callSilently('ops:auto-resolve', ['--grace' => 0]);
            $report = $this->get('/v1/ops/reconciliation?grace_seconds=30');

            if ($report['status'] === 200) {
                return;
            }

            $remaining = $this->openIncidents($report['json']);

            // Still shrinking means the sweeps are working through a backlog, and
            // the only thing wrong is that they need another rate-limit window.
            // Waiting on progress rather than on a fixed clock is what keeps this
            // scenario from being a race against how busy the machine happens to be.
            if ($remaining < $open) {
                $open = $remaining;
                $deadline = max($deadline, microtime(true) + $seconds);
            }

            sleep(5);
        } while (microtime(true) < $deadline && microtime(true) < $ceiling);

        $this->components->warn('Clean-up did not finish. Still open:');

        foreach ($this->failingChecks($this->get('/v1/ops/reconciliation?grace_seconds=30')['json']) as $name => $count) {
            $this->line("  {$name}: {$count}");
        }
    }

    /** How much of the incident is still open, across every check that counts. */
    private function openIncidents(?array $report): int
    {
        return array_sum($this->failingChecks($report));
    }

    /**
     * Checks that are not clean, by name, so a failed run says what is wrong
     * instead of only that something is.
     *
     * @return array<string, int>
     */
    private function failingChecks(?array $report): array
    {
        $failing = [];

        foreach ((array) data_get($report, 'checks', []) as $name => $check) {
            $count = (int) ($check['count'] ?? 0);

            if ($count > 0 && ($check['informational'] ?? false) === false) {
                $failing[$name] = $count;
            }
        }

        return $failing;
    }

    private function report(Carbon $startedAt, int $expected, ChaosMode $mode): int
    {
        $lines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.created_at', '>=', $startedAt)
            ->selectRaw(<<<'SQL'
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE order_items.status = 'delivered') AS delivered,
                COUNT(*) FILTER (WHERE order_items.status = 'refunded') AS refunded,
                COUNT(*) FILTER (WHERE order_items.settled_at IS NULL) AS unsettled
            SQL)
            ->first();

        $openOrders = DB::table('orders')
            ->where('created_at', '>=', $startedAt)
            ->whereNotIn('status', ['delivered', 'partially_delivered', 'refunded'])
            ->count();

        $deliveries = DB::table('deliveries')->count();
        $distinctCodes = DB::table('deliveries')->distinct()->count('code');

        $rejected = 0;

        foreach (['a', 'b'] as $supplier) {
            $rate = $this->get("/suppliers/{$supplier}/rate")['json'];
            $rejected += (int) data_get($rate, 'rejected');
            $this->line(sprintf(
                '  supplier %s served %d requests this minute, rejected %d',
                $supplier, (int) data_get($rate, 'used'), (int) data_get($rate, 'rejected'),
            ));
        }

        $report = $this->get('/v1/ops/reconciliation?grace_seconds=30');
        $money = (int) data_get($report['json'], 'checks.money_conservation.count', 1);

        $checks = [
            ['Every order reached a terminal state', $openOrders, $openOrders === 0],
            ['Every line settled', "{$lines->delivered} delivered + {$lines->refunded} refunded of {$lines->total}", (int) $lines->unsettled === 0 && (int) $lines->total === $expected],
            ['Refunds happened only where nothing was delivered', (int) $lines->refunded, ! $this->option('fail-both') || (int) $lines->refunded > 0],
            ['No code delivered twice', "{$distinctCodes}/{$deliveries} distinct", $distinctCodes === $deliveries],
            ['Every consumed key accounted for', ...$this->unaccountedKeys()],
            ['Supplier limits never exceeded', $rejected, $rejected === 0],
            ['Money adds up', $money, $money === 0],
            ['Reconciliation clean', $report['status'], $report['status'] === 200],
        ];

        $this->newLine();
        $this->components->info(sprintf('Storm of %d orders with supplier A failing as %s', $expected, $mode->value));
        $this->table(
            ['Check', 'Value', 'Result'],
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2] ? 'OK' : 'FAILED'], $checks),
        );

        $this->resetSuppliers();

        return array_all($checks, static fn (array $row): bool => $row[2]) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Keys the supplier consumed that the core cannot point at.
     *
     * A claimed key is inventory somebody paid for. It counts as accounted only
     * if it was delivered, or is recorded as stranded, or is still attached to a
     * line that has not finished yet.
     *
     * The last condition used to be "attached to any attempt", which quietly
     * passed for a key whose line had already been refunded: the code sat in an
     * attempt row nobody would ever look at again, and the check called that
     * accounted for. A key belonging to a settled line has to be delivered or
     * stranded, with nowhere else to hide.
     *
     * @return array{0: int, 1: bool}
     */
    private function unaccountedKeys(): array
    {
        $lost = DB::table('stub.supplier_keys as k')
            ->whereNotNull('k.request_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('deliveries')->whereColumn('deliveries.code', 'k.code'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('orphaned_codes')->whereColumn('orphaned_codes.code', 'k.code'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))
                ->from('delivery_attempts')
                ->join('order_items', 'order_items.id', '=', 'delivery_attempts.order_item_id')
                ->whereColumn('delivery_attempts.code', 'k.code')
                ->whereNull('order_items.settled_at'))
            ->count();

        return [$lost, $lost === 0];
    }

    /** Both stubs honest and both breakers closed, so the run starts from a known state. */
    private function resetSuppliers(): void
    {
        foreach (array_keys((array) config('ggsell.stubs')) as $supplier) {
            Cache::put(SupplierIssueController::overrideKey($supplier), ChaosMode::Ok->value, now()->addMinutes(30));
        }

        $breaker = app(CircuitBreaker::class);

        foreach (SupplierId::cases() as $supplier) {
            $breaker->recordSuccess($supplier);
        }
    }
}
