<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Gateways;

final readonly class RefundResponse
{
    private function __construct(
        public RefundOutcome $outcome,
        public ?string $reference,
        public ?string $reason,
        public ?int $httpStatus,
        public int $latencyMs,
    ) {}

    public static function ok(string $reference, int $httpStatus, int $latencyMs): self
    {
        return new self(RefundOutcome::Ok, $reference, null, $httpStatus, $latencyMs);
    }

    public static function rejected(string $reason, ?int $httpStatus, int $latencyMs): self
    {
        return new self(RefundOutcome::Rejected, null, $reason, $httpStatus, $latencyMs);
    }

    public static function unknown(string $reason, ?int $httpStatus, int $latencyMs): self
    {
        return new self(RefundOutcome::Unknown, null, $reason, $httpStatus, $latencyMs);
    }

    public function isOk(): bool
    {
        return $this->outcome === RefundOutcome::Ok;
    }
}
