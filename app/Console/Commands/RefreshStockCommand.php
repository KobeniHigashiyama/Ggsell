<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\RefreshStockProjection;
use Illuminate\Console\Command;

class RefreshStockCommand extends Command
{
    protected $signature = 'stock:refresh';

    protected $description = 'Обновить проекцию остатков витрины по данным поставщиков';

    public function handle(RefreshStockProjection $refreshStockProjection): int
    {
        $count = $refreshStockProjection->handle();

        $this->components->info("Обновлено SKU: {$count}.");

        return self::SUCCESS;
    }
}
