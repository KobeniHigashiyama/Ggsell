<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Stub\Supplier\ChaosMode;
use App\Stub\Supplier\SupplierIssueController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Change supplier stub behavior on the live stack.
 *
 * The mode lives in shared cache and applies immediately to every process
 * without container restarts or .env changes. Acceptance scenarios are therefore
 * reproducible with one command.
 */
class ChaosSupplierCommand extends Command
{
    protected $signature = 'chaos:supplier
        {supplier : a or b}
        {mode : ok | error | timeout | out_of_stock | duplicate | foreign_code | error_but_issued | random}
        {--ttl=600 : number of seconds to keep the mode active}';

    protected $description = 'Force a supplier stub to use a specific behavior, including the dishonest ones';

    public function handle(): int
    {
        $supplier = (string) $this->argument('supplier');
        $mode = (string) $this->argument('mode');

        if (! array_key_exists($supplier, (array) config('ggsell.stubs'))) {
            $this->components->error("Unknown supplier: {$supplier}.");

            return self::FAILURE;
        }

        if ($mode === 'random') {
            Cache::forget(SupplierIssueController::overrideKey($supplier));
            $this->components->info("Supplier {$supplier} restored to random behavior.");

            return self::SUCCESS;
        }

        if (ChaosMode::tryFrom($mode) === null) {
            $this->components->error("Unknown mode: {$mode}.");

            return self::FAILURE;
        }

        Cache::put(
            SupplierIssueController::overrideKey($supplier),
            $mode,
            now()->addSeconds((int) $this->option('ttl')),
        );

        $this->components->info(sprintf(
            'Supplier %s switched to %s mode for %d seconds.',
            $supplier, $mode, (int) $this->option('ttl'),
        ));

        if (ChaosMode::from($mode)->isDishonest()) {
            $this->components->warn(
                'This supplier now breaks its contract on purpose. Watch ops:auto-resolve and the reconciliation report.',
            );
        }

        return self::SUCCESS;
    }
}
