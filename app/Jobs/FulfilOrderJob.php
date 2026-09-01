<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Support\Log\Correlation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivery runs outside the HTTP request because the payment provider needs a
 * fast 200 while supplier timeouts and backoff can take seconds.
 *
 * ShouldBeUnique is an optimization, not a guarantee. Cache locks may expire or
 * disappear; database unique indexes provide exactly-once guarantees. Job
 * uniqueness only avoids redundant work after many webhooks for one order.
 */
class FulfilOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Queue-level retries are deliberately disabled. FulfilOrder owns retry
     * policy because it distinguishes timeouts from rejections; blind queue
     * retries would corrupt that accounting.
     */
    public int $tries = 1;

    public int $uniqueFor = 300;

    public ?string $correlationId;

    public function __construct(
        public int $orderId,
        ?string $correlationId = null,
    ) {
        // Capture the correlation ID when dispatching so worker activity remains
        // linked to the originating HTTP request.
        $this->correlationId = $correlationId ?? Correlation::id();

        // Supplier timeouts can hold delivery workers for seconds, so use a
        // dedicated queue instead of delaying unrelated short jobs.
        $this->onQueue('delivery');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(FulfilOrder $fulfilOrder): void
    {
        Correlation::set($this->correlationId);

        $fulfilOrder->handle($this->orderId);
    }
}
