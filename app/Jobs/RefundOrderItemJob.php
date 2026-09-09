<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Refunds\Actions\RefundOrderItem;
use App\Support\Log\Correlation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Returns the money for one undeliverable item.
 *
 * Queue retries are disabled for the same reason as delivery: RefundOrderItem
 * distinguishes a definitive gateway rejection from an unknown outcome, and a
 * blind retry would lose that distinction.
 */
class RefundOrderItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public ?string $correlationId;

    public function __construct(
        public int $orderItemId,
        public ?string $reason = null,
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? Correlation::id();
        // Settlement is background work; it must not delay a paid delivery.
        $this->onQueue(FulfilOrderItemJob::QUEUE_RECOVERY);
    }

    public function uniqueId(): string
    {
        return (string) $this->orderItemId;
    }

    public function handle(RefundOrderItem $refundOrderItem): void
    {
        Correlation::set($this->correlationId);

        $refundOrderItem->handle($this->orderItemId, $this->reason);
    }
}
