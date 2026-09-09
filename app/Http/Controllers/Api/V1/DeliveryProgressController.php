<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Delivery\Suppliers\SupplierRateLimiter;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\FulfilOrderItemJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Operational view of a delivery spike.
 *
 * During a surge the useful questions are how much is waiting, how much is
 * moving, and how much allowance is left. All three come from live state rather
 * than counters someone has to remember to increment.
 */
class DeliveryProgressController extends Controller
{
    public function __invoke(SupplierRateLimiter $rateLimiter): JsonResponse
    {
        $items = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('orders.paid_at')
            ->selectRaw('order_items.status, COUNT(*) AS total')
            ->groupBy('order_items.status')
            ->pluck('total', 'status');

        $byStatus = [];

        foreach (OrderItemStatus::cases() as $status) {
            $byStatus[$status->value] = (int) $items->get($status->value, 0);
        }

        $waiting = $byStatus[OrderItemStatus::Pending->value]
            + $byStatus[OrderItemStatus::Delivering->value]
            + $byStatus[OrderItemStatus::OutOfStock->value]
            + $byStatus[OrderItemStatus::DeliveryFailed->value];

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'items' => [
                'awaiting_delivery' => $waiting,
                'delivered' => $byStatus[OrderItemStatus::Delivered->value],
                'refunded' => $byStatus[OrderItemStatus::Refunded->value],
                'by_status' => $byStatus,
            ],
            'orders' => $this->ordersByStatus(),
            'queues' => $this->queueDepths(),
            'supplier_allowance' => $rateLimiter->snapshot(),
        ]);
    }

    /** @return array<string, int> */
    private function ordersByStatus(): array
    {
        $counts = DB::table('orders')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];

        foreach (OrderStatus::cases() as $status) {
            $result[$status->value] = (int) $counts->get($status->value, 0);
        }

        return $result;
    }

    /**
     * Depth of each delivery queue, including work that is waiting out a rate
     * limit rather than ready to run.
     *
     * @return array<string, array{ready: int, delayed: int}>
     */
    private function queueDepths(): array
    {
        $now = now()->timestamp;

        $rows = DB::table('jobs')
            ->whereIn('queue', [FulfilOrderItemJob::QUEUE_PAID, FulfilOrderItemJob::QUEUE_RECOVERY])
            ->selectRaw('queue, COUNT(*) FILTER (WHERE available_at <= ?) AS ready, COUNT(*) FILTER (WHERE available_at > ?) AS delayed', [$now, $now])
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $depths = [];

        foreach ([FulfilOrderItemJob::QUEUE_PAID, FulfilOrderItemJob::QUEUE_RECOVERY] as $queue) {
            $depths[$queue] = [
                'ready' => (int) ($rows->get($queue)->ready ?? 0),
                'delayed' => (int) ($rows->get($queue)->delayed ?? 0),
            ];
        }

        return $depths;
    }
}
