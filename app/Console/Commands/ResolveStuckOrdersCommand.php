<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Recovery\ResolveStuckOrders;
use Illuminate\Console\Command;

class ResolveStuckOrdersCommand extends Command
{
    protected $signature = 'orders:resolve-stuck
        {--limit= : максимум заказов за прогон}
        {--stuck-after= : через сколько секунд бездействия заказ считается зависшим}';

    protected $description = 'Поставить в очередь повторную выдачу по оплаченным, но не выданным заказам';

    public function handle(ResolveStuckOrders $resolveStuckOrders): int
    {
        $count = $resolveStuckOrders->handle(
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            stuckAfterSeconds: $this->option('stuck-after') !== null ? (int) $this->option('stuck-after') : null,
        );

        $this->components->info($count === 0
            ? 'Зависших заказов нет.'
            : "Поставлено в очередь на дожатие: {$count}.");

        return self::SUCCESS;
    }
}
