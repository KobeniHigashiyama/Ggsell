<?php

declare(strict_types=1);

namespace App\Domain\Ops\Recovery;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Jobs\FulfilOrderJob;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\DB;

/**
 * Background recovery for stuck orders (stage 4).
 *
 * A stuck order is paid, undelivered, and inactive beyond the threshold. Worker
 * failure, unresolved supplier timeout, and exhausted inventory are all handled
 * by rerunning the normal delivery flow.
 *
 * Recovery deliberately uses the same job as the payment webhook so it cannot
 * drift into a separate delivery path with different safety guarantees.
 */
final readonly class ResolveStuckOrders
{
    public function handle(?int $limit = null, ?int $stuckAfterSeconds = null): int
    {
        $limit ??= (int) config('ggsell.recovery.batch_size');
        $stuckAfter = $stuckAfterSeconds ?? (int) config('ggsell.recovery.stuck_after_seconds');
        $threshold = now()->subSeconds($stuckAfter);

        $ids = DB::transaction(fn () => DB::table('orders')
            ->whereIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Delivering->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ])
            ->where('updated_at', '<', $threshold)
            ->where('fulfilment_runs', '<', (int) config('ggsell.recovery.max_fulfilment_runs'))
            ->orderBy('updated_at')
            ->limit($limit)
            // SKIP LOCKED prevents concurrent scans from selecting the same rows.
            // Job uniqueness and FulfilOrder::claim() protect the work after this
            // transaction releases its locks.
            ->lock('FOR UPDATE SKIP LOCKED')
            ->pluck('id'));

        foreach ($ids as $id) {
            FulfilOrderJob::dispatch((int) $id);
        }

        if ($ids->isNotEmpty()) {
            DeliveryLog::warning('recovery.stuck_orders_requeued', [
                'count' => $ids->count(),
                'stuck_after_seconds' => $stuckAfter,
            ]);
        }

        return $ids->count();
    }
}
