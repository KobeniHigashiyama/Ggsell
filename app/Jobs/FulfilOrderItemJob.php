<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Delivery\Actions\FulfilOrderItem;
use App\Support\Log\Correlation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one order item.
 *
 * Paid work and background recovery ride different queues so a spike of new,
 * paying customers is served before the system's own retries.
 *
 * This is the real unit of delivery work. Splitting it out of the order-level
 * job means a supplier timeout on one line occupies one worker slot instead of
 * blocking the whole order, and a retry replays only the line that failed.
 *
 * ShouldBeUnique is an optimization, not a guarantee. Cache locks may expire or
 * disappear; database unique indexes provide exactly-once guarantees. Job
 * uniqueness only avoids redundant work when several triggers fire at once.
 */
class FulfilOrderItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Supplier retry policy still belongs to FulfilOrderItem, which is the only
     * thing that can tell a timeout from a rejection. What the queue owns now is
     * waiting: a line with no supplier allowance is released and comes back.
     *
     * A deadline rather than an attempt count, because the number of waits
     * depends on how long the spike lasts, not on anything about this job. Re-running
     * the action is safe at any point: it re-reads state under a lock, and the
     * per-item run budget still caps how often a supplier is actually contacted.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /** Delay before a released job is tried again, including after a failure. */
    public function backoff(): int
    {
        return (int) config('ggsell.suppliers.rate_limit_retry_seconds');
    }

    public int $uniqueFor = 300;

    /** Queue serving customers who just paid. Workers drain it first. */
    public const QUEUE_PAID = 'delivery-paid';

    /** Queue for the system's own retries, audits, and settlement. */
    public const QUEUE_RECOVERY = 'delivery-recovery';

    public ?string $correlationId;

    public function __construct(
        public int $orderItemId,
        string $queue = self::QUEUE_PAID,
        ?string $correlationId = null,
    ) {
        // Capture the correlation ID when dispatching so worker activity remains
        // linked to the originating HTTP request.
        $this->correlationId = $correlationId ?? Correlation::id();

        // Supplier timeouts can hold delivery workers for seconds, so use
        // dedicated queues instead of delaying unrelated short jobs.
        $this->onQueue($queue);
    }

    /** A delivery for a customer who is waiting right now. */
    public static function forPaidOrder(int $orderItemId): self
    {
        return new self($orderItemId, self::QUEUE_PAID);
    }

    /** Background work: recovery sweeps, audits, and anything already delayed. */
    public static function forRecovery(int $orderItemId): self
    {
        return new self($orderItemId, self::QUEUE_RECOVERY);
    }

    public function uniqueId(): string
    {
        return (string) $this->orderItemId;
    }

    public function handle(FulfilOrderItem $fulfilOrderItem): void
    {
        Correlation::set($this->correlationId);

        if ($fulfilOrderItem->handle($this->orderItemId)) {
            return;
        }

        // No supplier had allowance. Releasing puts this job back on its own queue
        // with a delay, so a spike becomes a wait rather than a lost order. It has
        // to be a release and not a fresh dispatch: the unique lock is held by the
        // job that is running, so a dispatch for the same line would be dropped.
        $this->release($this->backoff());
    }
}
