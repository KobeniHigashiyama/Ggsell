<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TalksToTheStack;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Reproduce the stage-2 spike: far more paid orders than the supplier accepts.
 *
 * Requests cross the real network stack, so the queue, the workers, and both
 * rate limiters are exercised the way they would be in production. The point of
 * the run is the pair of numbers at the end: everything delivered or refunded,
 * and zero requests rejected by the suppliers.
 */
class ChaosBurstCommand extends Command
{
    use TalksToTheStack;

    protected $signature = 'chaos:burst
        {--n=60 : number of orders to create and pay at once}
        {--sku=KEY-CS2-PRIME : SKU to order}
        {--wait=120 : seconds to wait for the backlog to drain}';

    protected $description = 'Create and pay many orders at once, then watch the queue drain without exceeding supplier limits';

    public function handle(): int
    {
        $count = (int) $this->option('n');

        $this->components->info("Creating and paying {$count} orders for {$this->option('sku')}");

        $orders = $this->createOrders($count);

        if ($orders === []) {
            $this->components->error('No orders were created.');

            return self::FAILURE;
        }

        $this->payAll($orders);
        $this->drain((int) $this->option('wait'), count($orders));

        return $this->report(count($orders));
    }

    /** @return list<array{order_id: string, amount_minor: int, currency: string}> */
    private function createOrders(int $count): array
    {
        $handles = [];
        $multi = curl_multi_init();

        for ($i = 0; $i < $count; $i++) {
            $handle = $this->makeHandle('POST', '/v1/orders', ['sku' => $this->option('sku')], [
                'Idempotency-Key: burst-'.Str::uuid(),
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
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

        $this->line(sprintf('  %d orders created', count($orders)));

        return $orders;
    }

    /** @param  list<array{order_id: string, amount_minor: int, currency: string}>  $orders */
    private function payAll(array $orders): void
    {
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

        $startedAt = hrtime(true);
        $this->runInParallel($multi, $handles);

        $this->line(sprintf(
            '  %d payments accepted in %d ms',
            count($orders),
            (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ));
    }

    /**
     * Watch the backlog shrink.
     *
     * Progress is read from the same endpoint an operator would use, so the run
     * proves the endpoint is useful and not only that the queue drains.
     */
    private function drain(int $seconds, int $expected): void
    {
        $deadline = microtime(true) + $seconds;
        $lastLine = '';

        do {
            $progress = $this->get('/v1/ops/delivery/progress')['json'];
            $waiting = (int) data_get($progress, 'items.awaiting_delivery');
            $delivered = (int) data_get($progress, 'items.delivered');
            $refunded = (int) data_get($progress, 'items.refunded');

            $line = sprintf(
                '  waiting %d, delivered %d, refunded %d, allowance %s',
                $waiting,
                $delivered,
                $refunded,
                collect(data_get($progress, 'supplier_allowance', []))
                    ->map(fn (array $row): string => "{$row['supplier']}:{$row['remaining']}")
                    ->implode(' '),
            );

            if ($line !== $lastLine) {
                $this->line($line);
                $lastLine = $line;
            }

            if ($waiting === 0 && $delivered + $refunded >= $expected) {
                return;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);
    }

    private function report(int $expected): int
    {
        $progress = $this->get('/v1/ops/delivery/progress')['json'];
        $settled = (int) data_get($progress, 'items.delivered') + (int) data_get($progress, 'items.refunded');

        $rejected = 0;

        foreach (['a', 'b'] as $supplier) {
            $rate = $this->get("/suppliers/{$supplier}/rate")['json'];
            $rejected += (int) data_get($rate, 'rejected');
            $this->line(sprintf(
                '  supplier %s served %d requests this minute, rejected %d',
                $supplier,
                (int) data_get($rate, 'used'),
                (int) data_get($rate, 'rejected'),
            ));
        }

        $checks = [
            ['Nothing lost: every line settled', "{$settled}/{$expected}", $settled >= $expected],
            ['Supplier limits never exceeded', $rejected, $rejected === 0],
        ];

        $this->newLine();
        $this->table(
            ['Check', 'Value', 'Result'],
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2] ? 'OK' : 'FAILED'], $checks),
        );

        return array_all($checks, static fn (array $row): bool => $row[2]) ? self::SUCCESS : self::FAILURE;
    }
}
