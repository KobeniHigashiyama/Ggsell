<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Recovery\ResolveStuckOrders;
use Illuminate\Console\Command;

class ResolveStuckOrdersCommand extends Command
{
    protected $signature = 'orders:resolve-stuck
        {--limit= : maximum orders per run}
        {--stuck-after= : seconds of inactivity before an order is considered stuck}';

    protected $description = 'Queue another delivery attempt for paid but undelivered orders';

    public function handle(ResolveStuckOrders $resolveStuckOrders): int
    {
        $count = $resolveStuckOrders->handle(
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            stuckAfterSeconds: $this->option('stuck-after') !== null ? (int) $this->option('stuck-after') : null,
        );

        $this->components->info($count === 0
            ? 'No stuck orders found.'
            : "Queued for recovery: {$count}.");

        return self::SUCCESS;
    }
}
