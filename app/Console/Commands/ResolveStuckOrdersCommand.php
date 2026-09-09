<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Recovery\ResolveStuckDeliveries;
use Illuminate\Console\Command;

class ResolveStuckOrdersCommand extends Command
{
    protected $signature = 'orders:resolve-stuck
        {--limit= : maximum order items per run}
        {--stuck-after= : seconds of inactivity before a line is considered stuck}';

    protected $description = 'Queue another delivery attempt for paid but undelivered order items';

    public function handle(ResolveStuckDeliveries $resolveStuckDeliveries): int
    {
        $count = $resolveStuckDeliveries->handle(
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            stuckAfterSeconds: $this->option('stuck-after') !== null ? (int) $this->option('stuck-after') : null,
        );

        $this->components->info($count === 0
            ? 'No stuck deliveries found.'
            : "Queued for recovery: {$count}.");

        return self::SUCCESS;
    }
}
