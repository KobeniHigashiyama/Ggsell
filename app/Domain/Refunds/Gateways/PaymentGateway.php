<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Gateways;

/**
 * Outbound money movement.
 *
 * The interface exists so tests can script gateway behavior the same way they
 * script suppliers: refunds must survive timeouts and duplicate calls, and those
 * are not reproducible against a real HTTP stub inside a unit of work.
 */
interface PaymentGateway
{
    /**
     * Returns money for one order item.
     *
     * @param  string  $refundRequestId  Idempotency key; a repeat must not move money twice.
     */
    public function refund(
        string $refundRequestId,
        string $orderRef,
        string $itemRef,
        int $amountMinor,
        string $currency,
    ): RefundResponse;
}
