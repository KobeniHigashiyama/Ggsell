<?php

declare(strict_types=1);

namespace App\Domain\Payments\DTO;

use Carbon\CarbonImmutable;

/**
 * Typed representation of the payment system webhook contract.
 */
final readonly class PaymentWebhookData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $eventId,
        public string $orderPublicId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?CarbonImmutable $occurredAt,
        public array $raw,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
