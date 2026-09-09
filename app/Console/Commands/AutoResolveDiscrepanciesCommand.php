<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ops\Recovery\AutoResolveDiscrepancies;
use Illuminate\Console\Command;

class AutoResolveDiscrepanciesCommand extends Command
{
    protected $signature = 'ops:auto-resolve
        {--limit= : maximum rows of each kind per run}
        {--grace= : seconds before a failed attempt is audited}';

    protected $description = 'Audit supplier claims, deliver or quarantine codes found behind errors, and return stranded codes';

    public function handle(AutoResolveDiscrepancies $autoResolveDiscrepancies): int
    {
        $result = $autoResolveDiscrepancies->handle(
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            graceSeconds: $this->option('grace') !== null ? (int) $this->option('grace') : null,
        );

        $this->table(
            ['Step', 'Count'],
            [
                ['Supplier claims audited', $result['audited']],
                ['Unresolved outcomes closed', $result['resolved_unknown']],
                ['Codes recovered and delivered', $result['recovered']],
                ['Codes quarantined', $result['quarantined']],
                ['Codes returned to suppliers', $result['returned']],
                ['Violations closed', $result['closed']],
            ],
        );

        return self::SUCCESS;
    }
}
