<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Str;

/**
 * HTTP access to the running stack for reproduction commands.
 *
 * Scenarios go through nginx and separate PHP-FPM processes on purpose: the
 * behavior under test is concurrency, queueing, and rate limiting, none of which
 * a single in-process connection can exercise honestly.
 */
trait TalksToTheStack
{
    /** @param  array<string, mixed>  $payload */
    protected function post(string $path, array $payload, array $headers = []): array
    {
        $handle = $this->makeHandle('POST', $path, $payload, $headers);
        $body = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
    }

    protected function get(string $path): array
    {
        $handle = $this->makeHandle('GET', $path);
        $body = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
    }

    /**
     * @param  list<\CurlHandle>  $handles
     * @return list<string> response bodies, in the order the handles were given
     */
    protected function runInParallel(\CurlMultiHandle $multi, array $handles): array
    {
        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $bodies = [];

        foreach ($handles as $handle) {
            $bodies[] = (string) curl_multi_getcontent($handle);
            curl_multi_remove_handle($multi, $handle);
        }

        curl_multi_close($multi);

        return $bodies;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $headers
     * @return \CurlHandle
     */
    protected function makeHandle(string $method, string $path, array $payload = [], array $headers = [])
    {
        $handle = curl_init(rtrim((string) config('ggsell.self_url'), '/').$path);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($payload !== []) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        return $handle;
    }

    /**
     * Pays for an order through the webhook endpoint.
     *
     * @param  array{order_id: string, amount_minor: int, currency: string}  $order
     */
    protected function payFor(array $order): array
    {
        return $this->post('/v1/webhooks/payment', [
            'event_id' => 'evt_'.Str::lower((string) Str::ulid()),
            'order_id' => $order['order_id'],
            'status' => 'paid',
            'amount' => number_format($order['amount_minor'] / 100, 2, '.', ''),
            'currency' => $order['currency'],
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Creates an order and returns the fields later steps need.
     *
     * @param  list<array{sku: string, quantity?: int}>  $lines
     * @return array{order_id: string, amount_minor: int, currency: string}|null
     */
    protected function createOrder(array $lines): ?array
    {
        $response = $this->post('/v1/orders', ['items' => $lines], [
            'Idempotency-Key: scenario-'.Str::uuid(),
        ]);

        $data = data_get($response['json'], 'data');

        if (! is_array($data) || ! isset($data['order_id'])) {
            return null;
        }

        return [
            'order_id' => (string) $data['order_id'],
            'amount_minor' => (int) $data['amount_minor'],
            'currency' => (string) $data['currency'],
        ];
    }

    /** Polls one order until it stops changing or the deadline passes. */
    protected function awaitOrder(string $orderId, int $seconds, callable $isDone): ?array
    {
        $deadline = microtime(true) + $seconds;

        do {
            $order = data_get($this->get("/v1/orders/{$orderId}")['json'], 'data');

            if (is_array($order) && $isDone($order)) {
                return $order;
            }

            usleep(400_000);
        } while (microtime(true) < $deadline);

        return is_array($order) ? $order : null;
    }
}
