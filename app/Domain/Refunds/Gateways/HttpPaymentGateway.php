<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Gateways;

use App\Support\Log\Correlation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the payment gateway.
 *
 * Like HttpSupplierClient it only classifies one call; retry policy belongs to
 * the action that owns the refund row and its history.
 */
final readonly class HttpPaymentGateway implements PaymentGateway
{
    public function refund(
        string $refundRequestId,
        string $orderRef,
        string $itemRef,
        int $amountMinor,
        string $currency,
    ): RefundResponse {
        $startedAt = hrtime(true);

        try {
            $response = Http::baseUrl(config('ggsell.payments.base_url'))
                ->timeout(config('ggsell.payments.timeout'))
                ->connectTimeout(config('ggsell.payments.connect_timeout'))
                ->withHeaders(['X-Correlation-Id' => Correlation::id()])
                ->acceptJson()
                ->post('/payments/refund', [
                    'refund_request_id' => $refundRequestId,
                    'order_id' => $orderRef,
                    'item_id' => $itemRef,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                ]);

            return $this->classify($response, $this->elapsedMs($startedAt));
        } catch (ConnectionException $e) {
            return $this->classifyConnectionFailure($e, $this->elapsedMs($startedAt));
        }
    }

    private function classify(Response $response, int $latencyMs): RefundResponse
    {
        $body = $this->decode($response);

        if ($response->successful()) {
            $reference = is_array($body) ? ($body['reference'] ?? null) : null;

            if (is_string($reference) && $reference !== '') {
                return RefundResponse::ok($reference, $response->status(), $latencyMs);
            }

            // A success without a reference may still have moved money. Unknown
            // keeps the refund retryable under the same request id.
            return RefundResponse::unknown('malformed_success', $response->status(), $latencyMs);
        }

        $reason = is_array($body) ? ($body['reason'] ?? null) : null;

        if (is_string($reason) && $reason !== '') {
            return RefundResponse::rejected($reason, $response->status(), $latencyMs);
        }

        return RefundResponse::unknown('opaque_error', $response->status(), $latencyMs);
    }

    private function classifyConnectionFailure(ConnectionException $e, int $latencyMs): RefundResponse
    {
        $message = $e->getMessage();

        $unreachable = str_contains($message, 'cURL error 7')
            || str_contains($message, 'cURL error 6')
            || str_contains($message, 'Failed to connect');

        return $unreachable
            ? RefundResponse::rejected('unreachable', null, $latencyMs)
            : RefundResponse::unknown('timeout', null, $latencyMs);
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
