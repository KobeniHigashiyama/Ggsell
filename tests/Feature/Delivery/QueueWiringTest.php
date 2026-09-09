<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Jobs\FulfilOrderItemJob;
use App\Jobs\FulfilOrderJob;
use App\Jobs\RefundOrderItemJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That the queues jobs are put on are the queues workers take them off.
 *
 * This is not a test of the framework. Queue names live in two places that
 * nothing connects — the job classes and the worker's command line — and when
 * they drifted apart during stage 2 every unit and feature test stayed green
 * while orders sat untouched on a queue nobody was consuming. Only a live run
 * showed it. The two places are compared here instead.
 */
class QueueWiringTest extends TestCase
{
    /** @return list<string> queues the worker container drains, in priority order */
    private function drainedQueues(): array
    {
        $compose = file_get_contents(base_path('compose.yaml'));

        $this->assertMatchesRegularExpression(
            '/queue:work[^\n]*--queue=([a-z0-9,\-]+)/',
            (string) $compose,
            'The worker must name the queues it drains.',
        );

        preg_match('/queue:work[^\n]*--queue=([a-z0-9,\-]+)/', (string) $compose, $matches);

        return explode(',', $matches[1]);
    }

    #[Test]
    public function every_queue_a_job_is_dispatched_to_is_drained_by_a_worker(): void
    {
        $drained = $this->drainedQueues();

        $queues = [
            FulfilOrderJob::class => (new FulfilOrderJob(1))->queue,
            FulfilOrderItemJob::class.'::forPaidOrder' => FulfilOrderItemJob::forPaidOrder(1)->queue,
            FulfilOrderItemJob::class.'::forRecovery' => FulfilOrderItemJob::forRecovery(1)->queue,
            RefundOrderItemJob::class => (new RefundOrderItemJob(1))->queue,
        ];

        foreach ($queues as $origin => $queue) {
            $this->assertContains(
                $queue,
                $drained,
                "{$origin} dispatches to '{$queue}', which no worker consumes.",
            );
        }
    }

    /**
     * Order is the priority: the worker takes the first non-empty queue in the
     * list, so a customer waiting on a delivery is served before the system's
     * own retries and settlement.
     */
    #[Test]
    public function paid_work_is_drained_before_background_work(): void
    {
        $drained = $this->drainedQueues();

        $paid = array_search(FulfilOrderItemJob::QUEUE_PAID, $drained, strict: true);
        $recovery = array_search(FulfilOrderItemJob::QUEUE_RECOVERY, $drained, strict: true);

        $this->assertIsInt($paid);
        $this->assertIsInt($recovery);
        $this->assertLessThan($recovery, $paid, 'Paid deliveries must be drained before background work.');
    }
}
