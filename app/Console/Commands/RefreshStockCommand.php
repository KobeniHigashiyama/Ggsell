<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\RefreshStockProjection;
use Illuminate\Console\Command;

class RefreshStockCommand extends Command
{
    protected $signature = 'stock:refresh';

    protected $description = 'Refresh the storefront stock projection from suppliers';

    public function handle(RefreshStockProjection $refreshStockProjection): int
    {
        $count = $refreshStockProjection->handle();

        $this->components->info("Updated SKUs: {$count}.");

        return self::SUCCESS;
    }
}
