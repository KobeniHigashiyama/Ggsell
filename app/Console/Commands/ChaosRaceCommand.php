<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\SyncStockFlag;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\CircuitBreaker;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reproduce race scenarios from acceptance criteria 1 and 2.
 *
 * Requests cross the real network stack through nginx and run in separate FPM
 * processes. In-process concurrency over one database connection would not
 * contend on the order row and could pass with a broken implementation.
 */
class ChaosRaceCommand extends Command
{
    protected $signature = 'chaos:race
        {--sku=KEY-CS2-PRIME : SKU for a new order}
        {--order= : use an existing order instead of creating one}
        {--n=50 : concurrency level}
        {--mode=distinct : distinct uses different event IDs; same reuses one event ID}
        {--wait=20 : seconds to wait for delivery}
        {--chaos : leave supplier stubs in their current random mode}';

    protected $description = 'Send concurrent payment webhooks to one order and verify exactly-once delivery';

    public function handle(): int
    {
        $concurrency = (int) $this->option('n');
        $mode = (string) $this->option('mode');

        $order = $this->resolveOrder();

        if ($order === null) {
            return self::FAILURE;
        }

        // The property under test is exactly-once under concurrency, not
        // supplier availability. Left in random mode a run can legitimately end
        // in delivery_failed because a timeout stayed unresolved, and this
        // harness would report that as a broken invariant when nothing broke.
        // So the stubs are pinned for the duration unless --chaos says not to.
        $restore = $this->option('chaos') ? null : $this->pinSuppliers();

        // Every run consumes a key. A check that stops working after eighty
        // invocations is not a reproducible check, so the harness tops the pool
        // back up. This reaches into the stub's own schema, which is acceptable
        // here and nowhere else: this command is test scaffolding, not core.
        $this->ensureSupplierStock($order);

        $this->components->info(sprintf(
            'Order %s (%s, %d %s), concurrency %d, event ID mode: %s',
            $order->public_id, $this->orderSku($order), intdiv($order->amount_minor, 100), $order->currency, $concurrency, $mode,
        ));

        $results = $this->fireWebhooks($order, $concurrency, $mode);
        $this->reportResponses($results);

        $order = $this->awaitSettlement($order, (int) $this->option('wait'));

        $result = $this->verify($order, $concurrency, $mode);

        if ($restore !== null) {
            $restore();
        }

        return $result;
    }

    /**
     * The SKU this scenario runs against.
     *
     * chaos:race deliberately uses a single-line order: the property under test
     * is contention on one order row, and extra lines would only add noise.
     */
    private function orderSku(Order $order): string
    {
        return (string) OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('position')
            ->value('sku');
    }

    private function ensureSupplierStock(Order $order, int $minimum = 10): void
    {
        $sku = $this->orderSku($order);

        // Count per supplier. The two pools are independent, so a combined total
        // can clear the threshold while one of them is empty and the fallback
        // leg of the scenario silently stops being exercised.
        $available = DB::table('stub.supplier_keys')
            ->selectRaw('supplier, count(*) AS available')
            ->where('sku', $sku)
            ->where('status', 'available')
            ->groupBy('supplier')
            ->pluck('available', 'supplier');

        $rows = [];

        foreach (['a', 'b'] as $supplier) {
            for ($i = (int) $available->get($supplier, 0); $i < $minimum; $i++) {
                $rows[] = [
                    'supplier' => $supplier,
                    'sku' => $sku,
                    'code' => sprintf('RACE-%s-%s', strtoupper($supplier), strtoupper(bin2hex(random_bytes(5)))),
                    'status' => 'available',
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table('stub.supplier_keys')->insertOrIgnore($rows);

        // Keep the storefront projection honest about what was just added.
        DB::statement(<<<'SQL'
            UPDATE product_stock ps
            SET available_count = agg.available, refreshed_at = now(), updated_at = now()
            FROM (
                SELECT ? AS sku, COUNT(*) AS available
                FROM stub.supplier_keys
                WHERE sku = ? AND status = 'available'
            ) agg
            WHERE ps.sku = agg.sku
        SQL, [$sku, $sku]);

        app(SyncStockFlag::class)->handle($sku);

        $this->line(sprintf('  Topped supplier pools for %s up to %d keys each', $sku, $minimum));
    }

    /**
     * Force both stubs to succeed, returning a callback that restores whatever
     * was set before so a run leaves the stack as it found it.
     *
     * @return callable(): void
     */
    private function pinSuppliers(): callable
    {
        $previous = [];

        foreach (array_keys((array) config('ggsell.stubs')) as $supplier) {
            $key = SupplierIssueController::overrideKey($supplier);
            $previous[$key] = Cache::get($key);
            Cache::put($key, 'ok', now()->addMinutes(10));
        }

        // The breaker outlives a run: earlier random failures can leave it open,
        // and then the chain skips every supplier and the order settles as
        // failed with no request ever sent. Pinning the stubs without clearing
        // the breaker would fix half the environment and still look flaky.
        $breaker = app(CircuitBreaker::class);

        foreach (SupplierId::cases() as $supplier) {
            $breaker->recordSuccess($supplier);
        }

        $this->line('  Supplier stubs pinned to ok and breakers cleared (--chaos keeps random modes)');

        return function () use ($previous): void {
            foreach ($previous as $key => $value) {
                $value === null ? Cache::forget($key) : Cache::put($key, $value, now()->addMinutes(10));
            }
        };
    }

    private function resolveOrder(): ?Order
    {
        if ($existing = $this->option('order')) {
            $order = Order::query()->where('public_id', $existing)->first();

            if ($order === null) {
                $this->components->error("Order {$existing} not found.");
            }

            return $order;
        }

        $response = $this->post('/v1/orders', ['sku' => $this->option('sku')], [
            'Idempotency-Key: race-'.Str::uuid(),
        ]);

        $publicId = data_get(json_decode((string) $response['body'], true), 'data.order_id');

        if (! is_string($publicId)) {
            $this->components->error('Failed to create order: '.$response['body']);

            return null;
        }

        return Order::query()->where('public_id', $publicId)->sole();
    }

    /**
     * Fire concurrent requests through curl_multi.
     *
     * Prepare connections first and start them in one loop so requests overlap
     * instead of running sequentially.
     *
     * @return list<array{status: int, body: string}>
     */
    private function fireWebhooks(Order $order, int $concurrency, string $mode): array
    {
        $sharedEventId = 'evt_'.Str::lower((string) Str::ulid());
        $multi = curl_multi_init();
        $handles = [];

        for ($i = 1; $i <= $concurrency; $i++) {
            $payload = [
                // distinct sends different events for one order and exercises
                // row locking; same exercises event_id deduplication itself.
                'event_id' => $mode === 'same' ? $sharedEventId : $sharedEventId.'_'.$i,
                'order_id' => $order->public_id,
                'status' => 'paid',
                // Preserve the exact major-unit amount; intdiv would lose cents
                // for non-round prices and leave the order in mismatch.
                'amount' => number_format($order->amount_minor / 100, 2, '.', ''),
                'currency' => $order->currency,
                'created_at' => now()->toIso8601String(),
            ];

            $handle = $this->makeHandle('/v1/webhooks/payment', $payload);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        $startedAt = hrtime(true);

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $results = [];

        foreach ($handles as $handle) {
            $results[] = [
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'body' => (string) curl_multi_getcontent($handle),
            ];
            curl_multi_remove_handle($multi, $handle);
        }

        curl_multi_close($multi);

        $this->line("  Burst of {$concurrency} requests completed in {$elapsedMs} ms");

        return $results;
    }

    /** @param list<array{status: int, body: string}> $results */
    private function reportResponses(array $results): void
    {
        $byStatus = [];
        $byOutcome = [];

        foreach ($results as $result) {
            $byStatus[$result['status']] = ($byStatus[$result['status']] ?? 0) + 1;
            $outcome = data_get(json_decode($result['body'], true), 'outcome', 'no response');
            $byOutcome[$outcome] = ($byOutcome[$outcome] ?? 0) + 1;
        }

        $rows = [];

        foreach ($byStatus as $status => $count) {
            $rows[] = ['HTTP '.$status, $count];
        }

        foreach ($byOutcome as $outcome => $count) {
            $rows[] = ['outcome: '.$outcome, $count];
        }

        $this->table(['Webhook response', 'Count'], $rows);
    }

    private function awaitSettlement(Order $order, int $seconds): Order
    {
        $deadline = microtime(true) + $seconds;

        do {
            $order->refresh();

            if ($order->status->isTerminal() || $order->status->isRecoverable()) {
                break;
            }

            usleep(300_000);
        } while (microtime(true) < $deadline);

        return $order;
    }

    private function verify(Order $order, int $concurrency, string $mode): int
    {
        $deliveries = DB::table('deliveries')->where('order_id', $order->id)->count();

        $requestIds = DB::table('delivery_attempts')->where('order_id', $order->id)->pluck('request_id');

        $issuedKeys = $requestIds->isEmpty() ? 0 : DB::table('stub.supplier_keys')
            ->whereIn('request_id', $requestIds)
            ->count();

        $paidEventsApplied = DB::table('payment_events')
            ->where('order_public_id', $order->public_id)
            ->where('outcome', 'applied')
            ->count();

        // The requirement is no loss and no duplication. Every delivered event
        // must leave a row; the checks below detect duplicates separately.
        $eventsRecorded = DB::table('payment_events')
            ->where('order_public_id', $order->public_id)
            ->count();

        $imbalance = DB::table('ledger_entries')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->count();

        $liability = -(int) DB::table('ledger_entries')
            ->where('order_id', $order->id)
            ->where('account', 'customer_liability')
            ->sum('amount_minor');

        $expectedEvents = $mode === 'same' ? 1 : $concurrency;

        $checks = [
            ['Events recorded without loss', $eventsRecorded, $eventsRecorded === $expectedEvents],
            ['Order deliveries', $deliveries, $deliveries === 1],
            ['Supplier keys consumed', $issuedKeys, $issuedKeys === 1],
            ['Paid events applied', $paidEventsApplied, $paidEventsApplied === 1],
            ['Unbalanced transactions', $imbalance, $imbalance === 0],
            ['Liability balance after delivery', $liability, $liability === 0],
            ['Order status', $order->status->value, $order->status->value === 'delivered'],
        ];

        $this->newLine();
        $this->table(
            ['Check', 'Value', 'Result'],
            array_map(
                static fn (array $row): array => [$row[0], $row[1], $row[2] ? 'OK' : 'FAILED'],
                $checks,
            ),
        );

        $failed = array_filter($checks, static fn (array $row): bool => ! $row[2]);

        if ($failed !== []) {
            $this->components->error(sprintf(
                '%d webhooks violated system invariants.', $concurrency,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d concurrent webhooks produced exactly one delivery, one key, and a balanced ledger.',
            $concurrency,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $headers
     * @return array{status: int, body: string}
     */
    private function post(string $path, array $payload, array $headers = []): array
    {
        $handle = $this->makeHandle($path, $payload, $headers);
        $body = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $headers
     * @return \CurlHandle
     */
    private function makeHandle(string $path, array $payload, array $headers = [])
    {
        $handle = curl_init(rtrim((string) config('ggsell.self_url'), '/').$path);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        return $handle;
    }
}
