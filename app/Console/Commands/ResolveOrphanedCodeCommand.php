<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Delivery\Models\OrphanedCode;
use App\Domain\Ops\Recovery\ResolveOrphanedCode;
use DomainException;
use Illuminate\Console\Command;

class ResolveOrphanedCodeCommand extends Command
{
    protected $signature = 'ops:resolve-orphan
        {id? : orphaned_codes row ID}
        {--resolution= : action taken for the code}
        {--by=ops : operator who resolved it}
        {--list : list unresolved codes and exit}';

    protected $description = 'Mark an orphaned code as resolved so reconciliation stops reporting it';

    public function handle(ResolveOrphanedCode $resolveOrphanedCode): int
    {
        if ($this->option('list') || $this->argument('id') === null) {
            return $this->listUnresolved();
        }

        $resolution = (string) ($this->option('resolution') ?: '');

        if ($resolution === '') {
            // A resolution without a description hides the outcome and makes
            // later incident analysis impossible.
            $this->components->error('Provide --resolution with the action taken for the code.');

            return self::FAILURE;
        }

        try {
            $orphan = $resolveOrphanedCode->handle(
                (int) $this->argument('id'),
                $resolution,
                (string) $this->option('by'),
            );
        } catch (DomainException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Code #{$orphan->id} marked as resolved.");

        return self::SUCCESS;
    }

    private function listUnresolved(): int
    {
        $rows = OrphanedCode::query()
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'order_id', 'supplier', 'reason', 'created_at']);

        if ($rows->isEmpty()) {
            $this->components->info('No unresolved orphaned codes found.');

            return self::SUCCESS;
        }

        // Do not display the code even here; it remains a valid unused key.
        $this->table(
            ['#', 'Order', 'Supplier', 'Reason', 'Created'],
            $rows->map(fn (OrphanedCode $o): array => [
                $o->id, $o->order_id, $o->supplier, $o->reason, $o->created_at,
            ])->all(),
        );

        $this->line('  Resolve: php artisan ops:resolve-orphan <#> --resolution="returned to supplier pool"');

        return self::SUCCESS;
    }
}
