<?php

declare(strict_types=1);

namespace App\Domain\Ops\Recovery;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Jobs\FulfilOrderItemJob;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\DB;

/**
 * Background recovery for stuck delivery lines.
 *
 * A stuck line belongs to a paid order, is not settled, and has been inactive
 * beyond the threshold. Worker failure, unresolved supplier timeout, and
 * exhausted inventory are all handled by rerunning the normal delivery flow.
 *
 * Recovery deliberately uses the same job as the payment webhook so it cannot
 * drift into a separate delivery path with different safety guarantees.
 *
 * It selects items rather than orders: one hopeless line must not keep its
 * healthy siblings out of recovery, and the run budget is per line.
 */
final readonly class ResolveStuckDeliveries
{
    public function handle(?int $limit = null, ?int $stuckAfterSeconds = null): int
    {
        $limit ??= (int) config('ggsell.recovery.batch_size');
        $stuckAfter = $stuckAfterSeconds ?? (int) config('ggsell.recovery.stuck_after_seconds');
        $threshold = now()->subSeconds($stuckAfter);

        $ids = DB::transaction(fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', OrderStatus::unsettledValues())
            ->whereNull('order_items.settled_at')
            ->where('order_items.updated_at', '<', $threshold)
            ->where(fn ($q) => $q
                ->where('order_items.fulfilment_runs', '<', (int) config('ggsell.recovery.max_fulfilment_runs'))
                // Or the line is holding a code that was never committed. That is
                // a delivery waiting to be finished rather than another attempt,
                // and it must not be locked out by a spent budget.
                ->orWhereExists(fn ($sub) => $sub
                    ->select(DB::raw('1'))
                    ->from('delivery_attempts')
                    ->whereColumn('delivery_attempts.order_item_id', 'order_items.id')
                    ->where('delivery_attempts.status', 'succeeded')
                    ->whereNotNull('delivery_attempts.code')
                    ->whereNull('delivery_attempts.quarantined_at')))

            // A line whose refund has started is no longer a delivery problem.
            // FulfilOrderItem refuses it anyway; this keeps it out of the queue.
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('refunds')
                ->whereColumn('refunds.order_item_id', 'order_items.id')
                ->where('refunds.status', '!=', 'failed'))
            ->orderBy('order_items.updated_at')
            ->limit($limit)
            // SKIP LOCKED prevents concurrent scans from selecting the same rows.
            // Job uniqueness and FulfilOrderItem::claim() protect the work after
            // this transaction releases its locks.
            ->lock('FOR UPDATE OF order_items SKIP LOCKED')
            ->pluck('order_items.id'));

        foreach ($ids as $id) {
            // Recovery yields to customers being served for the first time.
            dispatch(FulfilOrderItemJob::forRecovery((int) $id));
        }

        if ($ids->isNotEmpty()) {
            DeliveryLog::warning('recovery.stuck_items_requeued', [
                'count' => $ids->count(),
                'stuck_after_seconds' => $stuckAfter,
            ]);
        }

        return $ids->count();
    }
}
