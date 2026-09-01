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
        {id? : идентификатор строки orphaned_codes}
        {--resolution= : что с кодом сделали}
        {--by=ops : кто разобрал}
        {--list : показать неразобранные и выйти}';

    protected $description = 'Пометить осиротевший код разобранным, чтобы сверка перестала его показывать';

    public function handle(ResolveOrphanedCode $resolveOrphanedCode): int
    {
        if ($this->option('list') || $this->argument('id') === null) {
            return $this->listUnresolved();
        }

        $resolution = (string) ($this->option('resolution') ?: '');

        if ($resolution === '') {
            // A resolution without a description hides the outcome and makes
            // later incident analysis impossible.
            $this->components->error('Укажите --resolution: что именно сделали с кодом.');

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

        $this->components->info("Код #{$orphan->id} помечен разобранным.");

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
            $this->components->info('Неразобранных осиротевших кодов нет.');

            return self::SUCCESS;
        }

        // Do not display the code even here; it remains a valid unused key.
        $this->table(
            ['#', 'Заказ', 'Поставщик', 'Причина', 'Когда'],
            $rows->map(fn (OrphanedCode $o): array => [
                $o->id, $o->order_id, $o->supplier, $o->reason, $o->created_at,
            ])->all(),
        );

        $this->line('  Разобрать: php artisan ops:resolve-orphan <#> --resolution="вернул в пул поставщика"');

        return self::SUCCESS;
    }
}
