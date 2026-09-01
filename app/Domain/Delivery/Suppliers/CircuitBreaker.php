<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\Enums\SupplierId;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\Cache;

/**
 * Circuit breaker for a supplier.
 *
 * A timeout consumes worker time. Without a breaker, an unavailable supplier can
 * impose that cost on every queued order and turn an isolated outage into wider
 * degradation.
 *
 * The breaker does not block reconciliation because it resolves an already sent
 * request rather than creating a new obligation.
 */
final readonly class CircuitBreaker
{
    public function allows(SupplierId $supplier): bool
    {
        return Cache::get($this->openKey($supplier)) === null;
    }

    public function recordSuccess(SupplierId $supplier): void
    {
        Cache::forget($this->failureKey($supplier));
        Cache::forget($this->openKey($supplier));
    }

    public function recordFailure(SupplierId $supplier): void
    {
        $threshold = (int) config('ggsell.suppliers.breaker.threshold');
        $cooldown = (int) config('ggsell.suppliers.breaker.cooldown');

        $failures = Cache::increment($this->failureKey($supplier));

        if ($failures === false || $failures === 1) {
            // Cache::increment does not set a TTL, so define the consecutive-failure
            // window explicitly.
            Cache::put($this->failureKey($supplier), 1, now()->addSeconds($cooldown * 2));
            $failures = 1;
        }

        if ($failures >= $threshold) {
            Cache::put($this->openKey($supplier), true, now()->addSeconds($cooldown));
            Cache::forget($this->failureKey($supplier));

            DeliveryLog::warning('supplier.breaker_opened', [
                'supplier' => $supplier->value,
                'failures' => $failures,
                'cooldown_seconds' => $cooldown,
            ]);
        }
    }

    private function failureKey(SupplierId $supplier): string
    {
        return "supplier_breaker:{$supplier->value}:failures";
    }

    private function openKey(SupplierId $supplier): string
    {
        return "supplier_breaker:{$supplier->value}:open";
    }
}
