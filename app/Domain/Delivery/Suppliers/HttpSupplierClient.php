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

    public function verify(SupplierId $supplier, string $requestId): SupplierResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::baseUrl(config('ggsell.suppliers.base_url'))
                ->timeout(config('ggsell.suppliers.timeout'))
                ->connectTimeout(config('ggsell.suppliers.connect_timeout'))
                ->withHeaders(['X-Correlation-Id' => Correlation::id()])
                ->acceptJson()
                ->get("/suppliers/{$supplier->value}/requests/".rawurlencode($requestId));

            return $this->classifyVerification($response, $this->elapsedMs($startedAt));
        } catch (ConnectionException) {
            // Every transport failure is unknown here, including the ones issue()
            // treats as a definitive rejection. For issue() a refused connection
            // proves the request never arrived and nothing was bought. For verify
            // it proves only that we could not ask, and answering "no record" to
            // that question is how an audit closes an attempt the supplier never
            // spoke about, freeing the line to buy a second key or take a refund.
            return SupplierResponse::unknown('unreachable', null, $this->elapsedMs($startedAt));
        }
    }

    /**
     * Classifies an answer about what a supplier did, which is not the same job
     * as classifying an answer to a request for a code.
     *
     * issue() may read any contract-shaped error as a definitive rejection,
     * because a supplier that says "out of stock" has told us it issued nothing.
     * Nothing of the sort is true here: a 503 with a reason is still the supplier
     * declining to answer, and reading it as "no record" lets an audit close an
     * attempt on the strength of a question that was never answered.
     *
     * So exactly one response proves anything negative — the 404 the contract
     * defines as "this request is not in my registry". Everything else that is
     * not a code is unknown, and gets asked again.
     */
    private function classifyVerification(Response $response, int $latencyMs): SupplierResponse
    {
        if ($response->status() === 404) {
            return SupplierResponse::rejected('unknown_request', 404, $latencyMs);
        }

        $body = $this->decode($response);

        if ($response->successful()) {
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (is_string($code) && $code !== '') {
                $sku = is_array($body) ? ($body['sku'] ?? null) : null;

                return SupplierResponse::ok(
                    $code,
                    $response->status(),
                    $latencyMs,
                    is_string($sku) && $sku !== '' ? $sku : null,
                );
            }

            return SupplierResponse::unknown('malformed_success', $response->status(), $latencyMs);
        }

        $reason = is_array($body) ? ($body['reason'] ?? null) : null;

        return SupplierResponse::unknown(
            is_string($reason) && $reason !== '' ? $reason : 'opaque_error',
            $response->status(),
            $latencyMs,
        );
    }

    public function returnCode(SupplierId $supplier, string $code, string $reason): bool
    {
        try {
            return Http::baseUrl(config('ggsell.suppliers.base_url'))
                ->timeout(config('ggsell.suppliers.timeout'))
                ->connectTimeout(config('ggsell.suppliers.connect_timeout'))
                ->withHeaders(['X-Correlation-Id' => Correlation::id()])
                ->acceptJson()
                ->post("/suppliers/{$supplier->value}/return", [
                    'code' => $code,
                    'reason' => $reason,
                ])
                ->successful();
        } catch (ConnectionException) {
            // Returning a code is safe to repeat, so an unreachable supplier just
            // means the next sweep tries again.
            return false;
        }
    }

    private function classify(Response $response, int $latencyMs): SupplierResponse
    {
        $body = $this->decode($response);

        if ($response->successful()) {
            $code = is_array($body) ? ($body['code'] ?? null) : null;

            if (is_string($code) && $code !== '') {
                $sku = is_array($body) ? ($body['sku'] ?? null) : null;

                return SupplierResponse::ok(
                    $code,
                    $response->status(),
                    $latencyMs,
                    is_string($sku) && $sku !== '' ? $sku : null,
                );
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
