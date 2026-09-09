<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Ordering\Actions\RecalculateOrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Jobs\FulfilOrderItemJob;
use App\Support\Log\DeliveryLog;
use Illuminate\Support\Facades\DB;

/**
 * Order-level entry point for delivery.
 *
 * It owns no delivery logic. Its whole job is to fan the order out into one
 * independent unit of work per item, so a slow or failing supplier on one line
 * cannot hold up the others, and each line keeps its own attempt history,
 * retries, and run budget.
 */
final readonly class FulfilOrder
{
    public function __construct(
        private RecalculateOrderStatus $recalculateOrderStatus,
    ) {}

    /** @return int Number of items dispatched for delivery. */
    public function handle(int $orderId): int
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return 0;
        }

        if (! $order->status->moneyReceived()) {
            DeliveryLog::warning('fulfilment.refused_unpaid', [
                'order_id' => $order->public_id,
                'order_status' => $order->status->value,
            ]);

            return 0;
        }

        $itemIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->unsettled()
            ->orderBy('position')
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            // Every line is settled. Refresh the order in case a previous run
            // stopped between the last item update and the status recalculation.
            DB::transaction(function () use ($order): void {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->recalculateOrderStatus->handle($locked);
            });

            return 0;
        }

        foreach ($itemIds as $itemId) {
            dispatch(FulfilOrderItemJob::forPaidOrder((int) $itemId));
        }

        DeliveryLog::info('fulfilment.fanned_out', [
            'order_id' => $order->public_id,
            'items' => $itemIds->count(),
        ]);

        return $itemIds->count();
    }
}
