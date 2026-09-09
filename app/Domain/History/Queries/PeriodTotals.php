<?php

declare(strict_types=1);

namespace App\Domain\History\Queries;

use App\Domain\History\Enums\OrderEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Totals for a period, computed from the event log.
 *
 * The figures a business asks for are stated in business time: a payment counts
 * in the minute it happened, even if a late webhook recorded it later. The
 * cross-check against the ledger is stated in recording time instead, so both
 * sides cover exactly the same set of writes and any difference is a real
 * disagreement rather than an artifact of comparing two different clocks.
 */
final readonly class PeriodTotals
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     by_business_time: array{paid_minor: int, delivered_minor: int, refunded_minor: int, revenue_minor: int, cash_retained_minor: int, orders: int},
     *     ledger_check: array{matches: bool, events: array<string, int>, ledger: array<string, int>, deltas: array<string, int>}
     * }
     */
    public function handle(Carbon $from, Carbon $to): array
    {
        $business = $this->sumEvents($from, $to, 'occurred_at');
        $recorded = $this->sumEvents($from, $to, 'created_at');
        $ledger = $this->sumLedger($from, $to);

        $deltas = [
            'paid_minor' => $recorded['paid_minor'] - $ledger['paid_minor'],
            'delivered_minor' => $recorded['delivered_minor'] - $ledger['delivered_minor'],
            'refunded_minor' => $recorded['refunded_minor'] - $ledger['refunded_minor'],
        ];

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'by_business_time' => $business + [
                // Earned and kept are two different numbers and both are asked
                // for. Revenue is what was delivered: a refund is only ever made
                // for a line that was not, so it never reduces revenue and
                // subtracting it would report money the period never earned.
                'revenue_minor' => $business['delivered_minor'],
                // Cash is what came in less what went back out. It differs from
                // revenue by whatever is still owed at the end of the window.
                'cash_retained_minor' => $business['paid_minor'] - $business['refunded_minor'],
            ],
            'ledger_check' => [
                'matches' => array_all($deltas, static fn (int $delta): bool => $delta === 0),
                'events' => $recorded,
                'ledger' => $ledger,
                'deltas' => $deltas,
            ],
        ];
    }

    /**
     * @return array{paid_minor: int, delivered_minor: int, refunded_minor: int, orders: int}
     */
    private function sumEvents(Carbon $from, Carbon $to, string $timeColumn): array
    {
        $row = DB::table('order_events')
            ->whereBetween($timeColumn, [$from, $to])
            ->selectRaw(<<<'SQL'
                COALESCE(SUM((payload->>'amount_minor')::bigint) FILTER (WHERE type = ?), 0) AS paid,
                COALESCE(SUM((payload->>'amount_minor')::bigint) FILTER (WHERE type = ?), 0) AS delivered,
                COALESCE(SUM((payload->>'amount_minor')::bigint) FILTER (WHERE type = ?), 0) AS refunded,
                COUNT(DISTINCT order_id) FILTER (WHERE type = ?) AS orders
            SQL, [
                OrderEventType::PaymentApplied->value,
                OrderEventType::ItemDelivered->value,
                OrderEventType::ItemRefunded->value,
                OrderEventType::OrderCreated->value,
            ])
            ->first();

        return [
            'paid_minor' => (int) $row->paid,
            'delivered_minor' => (int) $row->delivered,
            'refunded_minor' => (int) $row->refunded,
            'orders' => (int) $row->orders,
        ];
    }

    /**
     * @return array{paid_minor: int, delivered_minor: int, refunded_minor: int}
     */
    private function sumLedger(Carbon $from, Carbon $to): array
    {
        $row = DB::table('ledger_entries')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(amount_minor) FILTER (WHERE account = 'cash' AND ref_type = 'payment_event'), 0) AS paid,
                COALESCE(-SUM(amount_minor) FILTER (WHERE account = 'revenue' AND ref_type = 'delivery_item'), 0) AS delivered,
                COALESCE(-SUM(amount_minor) FILTER (WHERE account = 'cash' AND ref_type = 'refund_item'), 0) AS refunded
            SQL)
            ->first();

        return [
            'paid_minor' => (int) $row->paid,
            'delivered_minor' => (int) $row->delivered,
            'refunded_minor' => (int) $row->refunded,
        ];
    }
}
