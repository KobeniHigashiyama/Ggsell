<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\Enums\SupplierId;
use App\Support\Log\Correlation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for a supplier.
 *
 * Its only responsibility is to classify one call. Retry and fallback policies
 * belong to the orchestrator, which has access to the full attempt history.
 */
final readonly class HttpSupplierClient implements SupplierClient
{
    public function issue(SupplierId $supplier, string $requestId, string $sku, string $orderRef): SupplierResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::baseUrl(config('ggsell.suppliers.base_url'))
                ->timeout(config('ggsell.suppliers.timeout'))
                ->connectTimeout(config('ggsell.suppliers.connect_timeout'))
                ->withHeaders(['X-Correlation-Id' => Correlation::id()])
                ->acceptJson()
                ->post("/suppliers/{$supplier->value}/issue", [
                    'request_id' => $requestId,
                    'sku' => $sku,
                    'order_id' => $orderRef,
                ]);

            return $this->classify($response, $this->elapsedMs($startedAt));
        } catch (ConnectionException $e) {
            return $this->classifyConnectionFailure($e, $this->elapsedMs($startedAt));
        }
    }

    private function classify(Response $response, int $latencyMs): SupplierResponse
    {
        $body = $this->decode($response);

        if ($response->successful()) {
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (is_string($code) && $code !== '') {
                return SupplierResponse::ok($code, $response->status(), $latencyMs);
            }

            // A successful response without a code may still have consumed a key.
            // Treat it as Unknown to prevent a duplicate delivery through fallback.
            return SupplierResponse::unknown('malformed_success', $response->status(), $latencyMs);
        }

        $reason = is_array($body) ? ($body['reason'] ?? null) : null;

        if (is_string($reason) && $reason !== '') {
            // A recognized contract response is the only definitive rejection that
            // permits fallback.
            return SupplierResponse::rejected($reason, $response->status(), $latencyMs);
        }

        // A proxy or load-balancer error may occur after the request reached the
        // supplier, so the outcome remains unknown.
        return SupplierResponse::unknown('opaque_error', $response->status(), $latencyMs);
    }

    /**
     * Distinguishes connection failures from response timeouts.
     *
     * If no TCP connection was established, the supplier could not issue a code
     * and fallback is safe. A response timeout means the request was sent and may
     * have been completed.
     */
    private function classifyConnectionFailure(ConnectionException $e, int $latencyMs): SupplierResponse
    {
        $message = $e->getMessage();

        $unreachable = str_contains($message, 'cURL error 7')   // connection refused
            || str_contains($message, 'cURL error 6')           // could not resolve host
            || str_contains($message, 'Failed to connect');

        return $unreachable
            ? SupplierResponse::rejected('unreachable', null, $latencyMs)
            : SupplierResponse::unknown('timeout', null, $latencyMs);
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response): ?array
    {
        try {
            $decoded = $response->json();

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
