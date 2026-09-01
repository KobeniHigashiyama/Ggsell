<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\PaymentOutcome;
use Illuminate\Support\Facades\App;

/**
 * Applies events accepted before their orders existed.
 *
 * Called immediately after order creation and independently by the scheduler.
 * The scheduled sweep closes the race between an event observing no order and
 * the order being created, while the direct call avoids delay in the common case.
 */
final readonly class ReplayPendingEvents
{
    /**
     * ApplyPaymentToOrder is resolved lazily because direct injection would create
     * a dependency cycle through CreateOrder and the payment journal.
     */
    private function apply(PaymentEvent $event): PaymentOutcome
    {
        return App::make(ApplyPaymentToOrder::class)->handle($event);
    }

    public function forOrder(string $orderPublicId): int
    {
        $events = PaymentEvent::query()
            ->whereNull('processed_at')
            ->where('order_public_id', $orderPublicId)
        // Apply events by their chronology rather than their delivery order.
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $this->apply($event);
        }

        return $events->count();
    }

    /**
     * Scheduled sweep for events accumulated without an order.
     *
     * The age window prevents events whose orders will never exist from occupying
     * every batch and starving legitimate webhooks that arrived before an order.
     *
     * Events outside the window remain visible as payments_not_applied during
     * reconciliation.
     */
    public function sweep(int $limit = 200): int
    {
        $applied = 0;
        $window = (int) config('ggsell.recovery.replay_window_hours');

        PaymentEvent::query()
            ->whereNull('processed_at')
            ->where('received_at', '>', now()->subHours($window))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PaymentEvent $event) use (&$applied): void {
                if ($this->apply($event) !== PaymentOutcome::PendingOrder) {
                    $applied++;
                }
            });

        return $applied;
    }
}
