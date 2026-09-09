<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ordering\Actions\SettleUnfulfillableItems;
use Illuminate\Console\Command;

class SettleOrdersCommand extends Command
{
    protected $signature = 'orders:settle
        {--limit= : maximum order items per run}
        {--give-up-after= : seconds after payment before an undelivered line is refunded}';

    protected $description = 'Refund paid order items that can no longer be delivered';

    public function handle(SettleUnfulfillableItems $settleUnfulfillableItems): int
    {
        $count = $settleUnfulfillableItems->handle(
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            giveUpAfterSeconds: $this->option('give-up-after') !== null ? (int) $this->option('give-up-after') : null,
        );

        $this->components->info($count === 0
            ? 'Nothing to settle.'
            : "Queued for refund: {$count}.");

        return self::SUCCESS;
    }
}
