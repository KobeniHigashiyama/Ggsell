<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Jobs\RefundOrderItemJob;
use App\Support\Log\PaymentLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drives every paid order to a terminal state.
 *
 * Delivery retries cannot run forever, and an order that is neither delivered
 * nor refunded is an open obligation nobody is watching. This scan converts
 * hopeless lines into refunds, which is what makes "paid equals delivered plus
 * refunded" hold in the end rather than only in the happy path.
 *
 * Eligibility is deliberately narrow. Only a line that already failed delivery
 * can be refunded, never one still queued or in flight, and never one where a
 * supplier might still be holding a code for us.
 */
final readonly class SettleUnfulfillableItems
{
    public function handle(?int $limit = null, ?int $giveUpAfterSeconds = null): int
    {
        $limit ??= (int) config('ggsell.settlement.batch_size');
        $giveUpAfter = $giveUpAfterSeconds ?? (int) config('ggsell.settlement.give_up_after_seconds');
        $maxRuns = (int) config('ggsell.recovery.max_fulfilment_runs');
        $deadline = now()->subSeconds($giveUpAfter);

        $ids = DB::transaction(fn () => $this->eligible($maxRuns, $deadline)
            ->orderBy('order_items.updated_at')
            ->limit($limit)
            ->lock('FOR UPDATE OF order_items SKIP LOCKED')
            ->pluck('order_items.id'));

        foreach ($ids as $id) {
            RefundOrderItemJob::dispatch((int) $id);
        }

        if ($ids->isNotEmpty()) {
            PaymentLog::warning('settlement.refunds_queued', [
                'count' => $ids->count(),
                'give_up_after_seconds' => $giveUpAfter,
            ]);
        }

        return $ids->count();
    }

    /**
     * Lines whose money should go back.
     *
     * @param  int  $maxRuns  Fulfilment runs after which delivery is considered hopeless.
     */
    private function eligible(int $maxRuns, Carbon $deadline): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('orders.paid_at')
            ->whereIn('orders.status', OrderStatus::unsettledValues())
            ->whereNull('order_items.settled_at')

            ->where(fn (Builder $q) => $q
                // A line that actually failed delivery, once retries are spent or
                // the customer has waited longer than hoping is worth.
                ->where(fn (Builder $failed) => $failed
                    ->whereIn('order_items.status', [
                        OrderItemStatus::OutOfStock->value,
                        OrderItemStatus::DeliveryFailed->value,
                    ])
                    ->where(fn (Builder $exhausted) => $exhausted
                        ->where('order_items.fulfilment_runs', '>=', $maxRuns)
                        ->orWhere('orders.paid_at', '<', $deadline)))

                // Or a line stranded mid-flight: a process killed on its last
                // allowed run leaves it in delivering with the budget spent, and
                // nothing else will ever pick it up again. The attempt guards
                // below still apply, so this only reaches lines where no supplier
                // could be holding a code.
                ->orWhere(fn (Builder $stranded) => $stranded
                    ->whereIn('order_items.status', [
                        OrderItemStatus::Pending->value,
                        OrderItemStatus::Delivering->value,
                    ])
                    ->where('order_items.fulfilment_runs', '>=', $maxRuns)))

            // A delivery may have been committed between the scan and the refund;
            // the refund action re-checks under a lock, this only avoids the noise.
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('deliveries')
                ->whereColumn('deliveries.order_item_id', 'order_items.id'))

            // Never refund while a supplier might still be holding a code for this
            // line: delivering and refunding one payment cannot be undone.
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('delivery_attempts')
                ->whereColumn('delivery_attempts.order_item_id', 'order_items.id')
                ->whereIn('delivery_attempts.status', ['pending', 'unknown', 'succeeded'])
                // A quarantined code is not this line's code, so it cannot be
                // delivered here and must not hold the refund hostage.
                ->whereNull('delivery_attempts.quarantined_at'))
            ->select('order_items.id');
    }
}
