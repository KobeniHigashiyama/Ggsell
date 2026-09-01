<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payments\Actions\ReplayPendingEvents;
use Illuminate\Console\Command;

class ReplayPendingEventsCommand extends Command
{
    protected $signature = 'payments:replay {--limit=200}';

    protected $description = 'Применить платёжные события, принятые раньше, чем появился их заказ';

    public function handle(ReplayPendingEvents $replayPendingEvents): int
    {
        $applied = $replayPendingEvents->sweep((int) $this->option('limit'));

        $this->components->info("Применено событий: {$applied}.");

        return self::SUCCESS;
    }
}
