<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Refunds\Gateways\PaymentGateway;
use App\Domain\Refunds\Gateways\RefundResponse;

/**
 * Scripted payment gateway.
 *
 * It reproduces the one property the real stub guarantees: a repeated
 * refund_request_id returns the original reference instead of moving money
 * again. Tests can therefore script a timeout and then assert that the retry
 * refunded once, which is the whole point of the request id.
 *
 * Calls are recorded so a test can count how many times money was actually
 * requested, not merely what the caller believed happened.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{request_id: string, order: string, item: string, amount_minor: int}> */
    public array $calls = [];

    /** @var list<RefundResponse> */
    private array $script = [];

    /** @var array<string, string> References already issued, by request id. */
    private array $issued = [];

    /** @var array<string, true> Request ids that have already been called. */
    private array $seen = [];

    /** @param  list<RefundResponse>  $responses */
    public function script(array $responses): self
    {
        $this->script = $responses;

        return $this;
    }

    /**
     * Marks a refund as already executed by the gateway.
     *
     * The first call still follows the script, usually a timeout. From the second
     * call onward the stored reference is returned, modeling a gateway that moved
     * the money but whose response was lost.
     */
    public function alreadyRefunded(string $requestId, string $reference): self
    {
        $this->issued[$requestId] = $reference;

        return $this;
    }

    public function refund(
        string $refundRequestId,
        string $orderRef,
        string $itemRef,
        int $amountMinor,
        string $currency,
    ): RefundResponse {
        $this->calls[] = [
            'request_id' => $refundRequestId,
            'order' => $orderRef,
            'item' => $itemRef,
            'amount_minor' => $amountMinor,
        ];

        $alreadyAsked = isset($this->seen[$refundRequestId]);
        $this->seen[$refundRequestId] = true;

        if ($alreadyAsked && isset($this->issued[$refundRequestId])) {
            return RefundResponse::ok($this->issued[$refundRequestId], 200, 4);
        }

        $next = array_shift($this->script);

        if ($next === null) {
            // Default behavior is a working gateway; tests that care script it.
            $reference = $this->issued[$refundRequestId] ??= 'rfd_'.substr(md5($refundRequestId), 0, 12);

            return RefundResponse::ok($reference, 200, 4);
        }

        if ($next->isOk() && $next->reference !== null) {
            $this->issued[$refundRequestId] = $next->reference;
        }

        return $next;
    }

    /** @return list<string> request ids from every call. */
    public function requestIds(): array
    {
        return array_column($this->calls, 'request_id');
    }
}
