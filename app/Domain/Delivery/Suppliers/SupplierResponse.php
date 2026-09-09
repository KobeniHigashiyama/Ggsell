<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

final readonly class SupplierResponse
{
    private function __construct(
        public SupplierOutcome $outcome,
        public ?string $code,
        public ?string $reason,
        public ?int $httpStatus,
        public int $latencyMs,
        /**
         * The SKU the supplier says this code belongs to.
         *
         * Null means the supplier did not say, which the core treats as unproven
         * rather than correct.
         */
        public ?string $sku = null,
    ) {}

    public static function ok(string $code, int $httpStatus, int $latencyMs, ?string $sku = null): self
    {
        return new self(SupplierOutcome::Ok, $code, null, $httpStatus, $latencyMs, $sku);
    }

    public static function rejected(string $reason, ?int $httpStatus, int $latencyMs): self
    {
        return new self(SupplierOutcome::Rejected, null, $reason, $httpStatus, $latencyMs);
    }

    public static function unknown(string $reason, ?int $httpStatus, int $latencyMs): self
    {
        return new self(SupplierOutcome::Unknown, null, $reason, $httpStatus, $latencyMs);
    }

    public function isOk(): bool
    {
        return $this->outcome === SupplierOutcome::Ok;
    }

    public function isOutOfStock(): bool
    {
        return $this->reason === 'out_of_stock';
    }
}
