<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FulfilOrderItemJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Blocks until the delivery queues are empty.
 *
 * Scenarios that reset the database need the workers to have finished first.
 * Without it a reset lands mid-job: the previous run's work disappears from
 * under a worker that has already reserved it, and the next scenario starts
 * against a queue that is still recovering from the last one.
 */
class WaitForIdleQueueCommand extends Command
{
    protected $signature = 'ops:wait-idle
        {--timeout=60 : seconds to wait before giving up}
        {--quiet-for=2 : seconds the queues must stay empty to count as idle}';

    protected $description = 'Wait until the delivery queues are drained';

    public function handle(): int
    {
        $deadline = microtime(true) + (int) $this->option('timeout');
        $quietFor = (float) $this->option('quiet-for');
        $quietSince = null;

        do {
            $pending = DB::table('jobs')
                ->whereIn('queue', [FulfilOrderItemJob::QUEUE_PAID, FulfilOrderItemJob::QUEUE_RECOVERY])
                ->count();

            if ($pending > 0) {
                $quietSince = null;
                usleep(200_000);

                continue;
            }

            // Empty once is not idle: a job being processed right now will queue
            // its follow-up work a moment later.
            $quietSince ??= microtime(true);

            if (microtime(true) - $quietSince >= $quietFor) {
                $this->components->info('Delivery queues are idle.');

                return self::SUCCESS;
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        $this->components->warn('Delivery queues were still busy when the wait expired.');

        return self::SUCCESS;
    }
}
