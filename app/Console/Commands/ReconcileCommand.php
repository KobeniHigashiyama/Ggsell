<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Reconciliation\ReconciliationReport;
use Illuminate\Console\Command;

/**
 * Run reconciliation from the command line. Return a non-zero status on
 * discrepancies so CI and monitoring do not need to parse output.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'orders:reconcile
        {--grace= : seconds an order may process before it becomes a discrepancy}
        {--json : output the raw report}';

    protected $description = 'Reconcile orders, deliveries, and the ledger';

    public function handle(ReconciliationReport $report): int
    {
        $grace = $this->option('grace');
        $result = $report->build($grace !== null ? (int) $grace : null);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report->isHealthy($result) ? self::SUCCESS : self::FAILURE;
        }

        $rows = [];

        foreach ($result['checks'] as $name => $check) {
            $rows[] = [
                $name,
                $check['count'].(($check['truncated'] ?? false) ? ' (sample truncated)' : ''),
                match (true) {
                    $check['count'] === 0 => 'OK',
                    ($check['informational'] ?? false) => 'informational',
                    default => 'DISCREPANCY',
                },
                $check['description'],
            ];
        }

        $this->table(['Check', 'Found', 'Result', 'Description'], $rows);

        foreach ($result['checks']['liability_mismatch']['by_currency'] as $line) {
            $this->line(sprintf(
                '  %s - ledger liability: %d, order liability: %d, difference: %d minor units',
                $line['currency'],
                $line['ledger_liability_minor'],
                $line['orders_outstanding_minor'],
                $line['delta_minor'],
            ));
        }

        if ($report->isHealthy($result)) {
            $this->components->info('No discrepancies found.');

            return self::SUCCESS;
        }

        $this->components->error('Discrepancies found. Run orders:reconcile --json for details.');

        return self::FAILURE;
    }
}
