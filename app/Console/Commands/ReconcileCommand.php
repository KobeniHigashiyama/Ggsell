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
        {--grace= : сколько секунд заказу позволено обрабатываться, прежде чем он считается расхождением}
        {--json : выдать сырой отчёт}';

    protected $description = 'Свести заказы, выдачи и денежный журнал между собой';

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
                $check['count'].(($check['truncated'] ?? false) ? ' (примеров показано меньше)' : ''),
                match (true) {
                    $check['count'] === 0 => 'OK',
                    ($check['informational'] ?? false) => 'к сведению',
                    default => 'РАСХОЖДЕНИЕ',
                },
                $check['description'],
            ];
        }

        $this->table(['Проверка', 'Найдено', 'Итог', 'Что означает'], $rows);

        foreach ($result['checks']['liability_mismatch']['by_currency'] as $line) {
            $this->line(sprintf(
                '  %s — обязательства по журналу: %d, по заказам: %d, расхождение: %d (в минорных единицах)',
                $line['currency'],
                $line['ledger_liability_minor'],
                $line['orders_outstanding_minor'],
                $line['delta_minor'],
            ));
        }

        if ($report->isHealthy($result)) {
            $this->components->info('Расхождений нет.');

            return self::SUCCESS;
        }

        $this->components->error('Найдены расхождения, подробности: orders:reconcile --json');

        return self::FAILURE;
    }
}
