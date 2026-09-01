<?php

declare(strict_types=1);

namespace App\Domain\Ops\Recovery;

use App\Domain\Delivery\Models\OrphanedCode;
use App\Support\Log\DeliveryLog;
use DomainException;

/**
 * Marks an orphaned code as resolved.
 *
 * Resolved discrepancies must leave the reconciliation report so it remains a
 * useful signal for future incidents.
 *
 * Resolution is manual because the core neither owns the supplier pool nor makes
 * the financial decision to write off inventory. This records the operator action.
 */
final readonly class ResolveOrphanedCode
{
    public function handle(int $id, string $resolution, string $resolvedBy = 'ops'): OrphanedCode
    {
        $orphan = OrphanedCode::query()->findOrFail($id);

        if ($orphan->resolved_at !== null) {
            throw new DomainException("Orphaned code {$id} is already resolved: {$orphan->resolution}");
        }

        $orphan->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $resolvedBy,
            'resolution' => $resolution,
        ])->save();

        // This changes inventory rather than money, so the delivery log is the
        // appropriate audit trail.
        DeliveryLog::warning('orphaned_code.resolved', [
            'orphaned_code_id' => $orphan->id,
            'order_id' => $orphan->order_id,
            'supplier' => $orphan->supplier,
            'resolution' => $resolution,
            'resolved_by' => $resolvedBy,
        ]);

        return $orphan;
    }
}
