<?php

declare(strict_types=1);

namespace App\Domain\Ops\Reconciliation;

use App\Domain\Ledger\Account;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation report (stage 4).
 *
 * Compares independent representations of the same business state: payment
 * events, item state, deliveries, refunds, and the ledger. Every health check is
 * empty in a consistent system, so any result requires investigation.
 *
 * Since stage 2 the unit of comparison is the order item, because an order may
 * be half delivered and half refunded and still be perfectly consistent.
 *
 * The report uses one REPEATABLE READ snapshot so normal commits between checks
 * cannot produce false discrepancies.
 */
final readonly class ReconciliationReport
{
    private const SAMPLE_SIZE = 50;

    public function build(?int $graceSeconds = null): array
    {
        $grace = $graceSeconds ?? (int) config('ggsell.recovery.stuck_after_seconds');

        return DB::transaction(function () use ($grace): array {
            // Isolation can be changed only by the first query, so raise it only
            // when this method owns the transaction. Nested callers already use
            // the enclosing transaction's snapshot.
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            $threshold = now()->subSeconds($grace);

            return [
                'generated_at' => now()->toIso8601String(),
                'grace_seconds' => $grace,
                'checks' => [
                    'paid_not_delivered' => $this->paidNotDelivered($threshold),
                    'delivered_not_paid' => $this->deliveredNotPaid(),
                    'payments_not_applied' => $this->paymentsNotApplied($threshold),
                    'payments_reversed' => $this->paymentsReversed(),
                    'delivered_not_settled' => $this->deliveredNotSettled(),
                    'duplicate_payments' => $this->duplicatePayments(),
                    'uncommitted_codes' => $this->uncommittedCodes($threshold),
                    'unresolved_attempts' => $this->unresolvedAttempts($threshold),
                    'orphaned_codes' => $this->orphanedCodes(),
                    'supplier_violations' => $this->supplierViolations(),
                    'refunds_unfinished' => $this->refundsUnfinished($threshold),
                    'ledger_imbalance' => $this->ledgerImbalance(),
                    'liability_mismatch' => $this->liabilityMismatch(),
                    'money_conservation' => $this->moneyConservation(),
                ],
            ];
        });
    }

    /**
     * Informational checks do not affect overall health.
     *
     * Signals that cannot be reliably distinguished from normal operation remain
     * visible without turning the report unhealthy.
     */
    public function isHealthy(array $report): bool
    {
        foreach ($report['checks'] as $check) {
            if (($check['informational'] ?? false) === false && ($check['count'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Paid but not delivered.
     *
     * The primary financial risk: payment was received but no product was delivered.
     *
     * Age is based on paid_at because delivery retries update updated_at and would
     * otherwise keep a persistently stuck order looking recent.
     */
    private function paidNotDelivered(Carbon $threshold): array
    {
        // settled_at excludes refunded lines: money that went back is not a loss,
        // it is the other correct ending.
        $query = fn (): Builder => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('order_items.settled_at')
            ->whereIn('orders.status', OrderStatus::unsettledValues())
            ->where('orders.paid_at', '<', $threshold);

        return $this->summarise(
            'Payment received but product neither delivered nor refunded.',
            $query,
            fn (Builder $q): Collection => $q->orderBy('orders.paid_at')
                ->limit(self::SAMPLE_SIZE)
                ->get([
                    'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                    'order_items.status', 'order_items.sku', 'order_items.amount_minor',
                    'orders.paid_at', 'order_items.failure_reason',
                    'order_items.fulfilment_runs',
                ]),
            amountColumn: 'order_items.amount_minor',
        );
    }

    /**
     * Delivered but not paid.
     *
     * This is impossible in the normal flow because delivery starts only after
     * payment, so any result indicates a serious invariant violation.
     */
    private function deliveredNotPaid(): array
    {
        $query = fn (): Builder => DB::table('deliveries')
            ->join('orders', 'orders.id', '=', 'deliveries.order_id')
            ->whereNull('orders.paid_at');

        return $this->summarise(
            'Product delivered without confirmed payment.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id AS order_id', 'orders.status', 'deliveries.delivered_at',
            ]),
        );
    }

    /**
     * Delivery exists while the item is not in its final status.
     *
     * The customer owns the product but the API hides its code behind the status.
     * CommitDelivery should make this state unreachable; this check guards that
     * invariant against future changes or direct database writes.
     */
    private function deliveredNotSettled(): array
    {
        $query = fn (): Builder => DB::table('deliveries')
            ->join('order_items', 'order_items.id', '=', 'deliveries.order_item_id')
            ->join('orders', 'orders.id', '=', 'deliveries.order_id')
            ->where('order_items.status', '!=', OrderItemStatus::Delivered->value);

        return $this->summarise(
            'Delivery recorded but item not transitioned to delivered.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                'order_items.status', 'deliveries.delivered_at',
            ]),
        );
    }

    /**
     * Multiple successful payment events for one order.
     *
     * A later payment becomes no_op because the order is already paid. A distinct
     * event ID may still represent gateway reissuance or an actual double charge,
     * so it remains visible for review.
     *
     * This check is informational because webhook data cannot distinguish a
     * double charge from legitimate repeated delivery under a new identifier.
     */
    private function duplicatePayments(): array
    {
        // Wrap grouping in a subquery so COUNT returns the number of groups rather
        // than the size of each group.
        $grouped = fn (): Builder => DB::table('payment_events')
            ->selectRaw('order_public_id, COUNT(*) AS payments, SUM(amount_minor) AS amount_minor')
            ->where('status', 'paid')
            ->whereIn('outcome', ['applied', 'no_op'])
            ->groupBy('order_public_id')
            ->havingRaw('COUNT(*) > 1');

        return [
            'description' => 'More than one successful payment was recorded for the order (informational).',
            'informational' => true,
            'count' => DB::query()->fromSub($grouped(), 'dup')->count(),
            'items' => DB::query()->fromSub($grouped(), 'dup')->limit(self::SAMPLE_SIZE)->get()->all(),
        ];
    }

    /**
     * Payment was received but not applied by the system.
     *
     * This captures amount mismatches and events whose orders never appeared. The
     * acquirer has a payment, but no order transition or ledger entry exists, so
     * paid_not_delivered cannot detect them.
     */
    private function paymentsNotApplied(Carbon $threshold): array
    {
        $query = fn (): Builder => DB::table('payment_events')
            ->where('payment_events.status', 'paid')
            ->where('payment_events.received_at', '<', $threshold)
            ->where(fn (Builder $q) => $q
                ->whereNull('payment_events.processed_at')
                ->orWhere('payment_events.outcome', 'mismatch'));

        return $this->summarise(
            'Payment received but not applied due to an amount mismatch or missing order.',
            $query,
            fn (Builder $q): Collection => $q->orderBy('payment_events.received_at')
                ->limit(self::SAMPLE_SIZE)
                ->get([
                    'payment_events.event_id', 'payment_events.order_public_id',
                    'payment_events.amount_minor', 'payment_events.currency',
                    'payment_events.outcome', 'payment_events.received_at',
                ]),
            amountColumn: 'payment_events.amount_minor',
        );
    }

    /**
     * Payment failure reported after money was already received.
     *
     * Refunds are intentionally manual, so the event is logged without reversing
     * the order. It still requires review because the product may be delivered
     * while the payment is revoked.
     */
    private function paymentsReversed(): array
    {
        // Select by outcome rather than failure presence. Stale failures and a
        // failure followed by a successful payment are normal. Only no_op means
        // the failure was rejected because payment was already recorded. Count
        // orders rather than events to avoid overstating repeated failures.
        $query = fn (): Builder => DB::table('orders')
            ->whereNotNull('orders.paid_at')
            ->whereExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('payment_events')
                ->whereColumn('payment_events.order_public_id', 'orders.public_id')
                ->where('payment_events.status', 'failed')
                ->where('payment_events.outcome', 'no_op'));

        return $this->summarise(
            'Payment failure reported for an order whose payment was already received.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id', 'orders.status', 'orders.amount_minor', 'orders.paid_at',
            ]),
            amountColumn: 'orders.amount_minor',
        );
    }

    /**
     * Supplier returned a code but no matching delivery was committed.
     *
     * This indicates a process failure between saving the supplier response and
     * writing deliveries. The succeeded attempt is not unresolved, but inventory
     * has already been consumed.
     */
    private function uncommittedCodes(Carbon $threshold): array
    {
        // Compare against the delivered code, not merely delivery existence, so a
        // second successful attempt for the same order cannot disappear.
        $query = fn (): Builder => DB::table('delivery_attempts')
            ->join('order_items', 'order_items.id', '=', 'delivery_attempts.order_item_id')
            ->join('orders', 'orders.id', '=', 'delivery_attempts.order_id')
            ->where('delivery_attempts.status', 'succeeded')
            ->whereNotNull('delivery_attempts.code')
            ->where('delivery_attempts.started_at', '<', $threshold)
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('deliveries')
                ->whereColumn('deliveries.order_item_id', 'delivery_attempts.order_item_id')
                ->whereColumn('deliveries.code', 'delivery_attempts.code'))
        // Exclude codes already recorded as orphaned so one incident appears in
        // exactly one check.
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('orphaned_codes')
                ->whereColumn('orphaned_codes.order_item_id', 'delivery_attempts.order_item_id')
                ->whereColumn('orphaned_codes.code', 'delivery_attempts.code'));

        return $this->summarise(
            'Supplier issued a code but delivery was not committed.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                'delivery_attempts.request_id', 'delivery_attempts.supplier',
                'delivery_attempts.started_at',
            ]),
        );
    }

    /**
     * Attempts with unresolved outcomes.
     *
     * While an attempt remains unresolved, fallback and final failure are unsafe.
     * Attempts for delivered orders are excluded because delivery proves the outcome.
     */
    private function unresolvedAttempts(Carbon $threshold): array
    {
        $query = fn (): Builder => DB::table('delivery_attempts')
            ->join('order_items', 'order_items.id', '=', 'delivery_attempts.order_item_id')
            ->join('orders', 'orders.id', '=', 'delivery_attempts.order_id')
            ->leftJoin('deliveries', 'deliveries.order_item_id', '=', 'delivery_attempts.order_item_id')
            ->whereNull('deliveries.id')
            ->whereIn('delivery_attempts.status', ['pending', 'unknown'])
            ->where('delivery_attempts.started_at', '<', $threshold);

        return $this->summarise(
            'Supplier request has an unresolved outcome.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                'delivery_attempts.request_id', 'delivery_attempts.supplier',
                'delivery_attempts.status', 'delivery_attempts.attempt_no', 'delivery_attempts.started_at',
            ]),
        );
    }

    private function orphanedCodes(): array
    {
        $query = fn (): Builder => DB::table('orphaned_codes')
            ->join('order_items', 'order_items.id', '=', 'orphaned_codes.order_item_id')
            ->join('orders', 'orders.id', '=', 'orphaned_codes.order_id')
            ->whereNull('orphaned_codes.resolved_at');

        return $this->summarise(
            'Supplier code was received but not assigned to an item.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                'orphaned_codes.supplier', 'orphaned_codes.reason', 'orphaned_codes.created_at',
            ]),
        );
    }

    /**
     * Refunds the gateway never confirmed, and refunds it did confirm that never
     * closed their line.
     *
     * Both are money the system cannot account for. An unfinished refund may or
     * may not have moved cash, so the gateway has to be asked again; a confirmed
     * refund whose line is not refunded means the two records disagree about what
     * the customer is owed. The table had an index for this working set from the
     * start and nothing was reading it.
     */
    private function refundsUnfinished(Carbon $threshold): array
    {
        $query = fn (): Builder => DB::table('refunds')
            ->join('order_items', 'order_items.id', '=', 'refunds.order_item_id')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->where('refunds.requested_at', '<', $threshold)
            ->where(fn (Builder $q) => $q
                ->whereIn('refunds.status', ['pending', 'unknown'])
                ->orWhere(fn (Builder $mismatch) => $mismatch
                    ->where('refunds.status', 'succeeded')
                    ->where('order_items.status', '!=', 'refunded')));

        return $this->summarise(
            'Refund never confirmed by the gateway, or confirmed without settling its line.',
            $query,
            fn (Builder $q): Collection => $q->orderBy('refunds.requested_at')
                ->limit(self::SAMPLE_SIZE)
                ->get([
                    'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                    'refunds.refund_request_id', 'refunds.status AS refund_status',
                    'order_items.status AS item_status', 'refunds.amount_minor',
                    'refunds.requested_at',
                ]),
            amountColumn: 'refunds.amount_minor',
        );
    }

    /**
     * Open supplier contract violations.
     *
     * A violation stays open until the stranded code is back with its supplier
     * and the affected line is settled, so a row here always means either a
     * customer still owed something or inventory still loose in the world.
     *
     * ops:auto-resolve closes these without an operator; anything that survives
     * several sweeps is a supplier problem rather than a system one.
     */
    private function supplierViolations(): array
    {
        $query = fn (): Builder => DB::table('supplier_violations')
            ->leftJoin('order_items', 'order_items.id', '=', 'supplier_violations.order_item_id')
            ->leftJoin('orders', 'orders.id', '=', 'supplier_violations.order_id')
            ->whereNull('supplier_violations.resolved_at');

        return $this->summarise(
            'Supplier broke its contract and the incident is still open.',
            $query,
            fn (Builder $q): Collection => $q->orderBy('supplier_violations.detected_at')
                ->limit(self::SAMPLE_SIZE)
                ->get([
                    'orders.public_id AS order_id', 'order_items.public_id AS item_id',
                    'supplier_violations.supplier', 'supplier_violations.kind',
                    'supplier_violations.detail', 'supplier_violations.detected_at',
                ]),
        );
    }

    /**
     * Unbalanced ledger transactions.
     *
     * PostTransaction rejects unbalanced entries before writing them. This check
     * protects against direct database edits and future code regressions.
     */
    private function ledgerImbalance(): array
    {
        $rows = DB::table('ledger_entries')
            ->select('transaction_id', DB::raw('SUM(amount_minor) AS imbalance'))
            ->groupBy('transaction_id')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->limit(self::SAMPLE_SIZE)
            ->get();

        return [
            'description' => 'Ledger transactions whose entries do not sum to zero.',
            'count' => $rows->count(),
            'items' => $rows->all(),
        ];
    }

    /**
     * Customer liability balance compared with actual item state.
     *
     * Customer liability must exactly match the total value of paid lines that
     * are neither delivered nor refunded. This connects the ledger to domain
     * state and exposes both missing and duplicate exactly-once operations even
     * when entries balance internally.
     *
     * Values are compared per currency to prevent unrelated amounts from canceling.
     */
    private function liabilityMismatch(): array
    {
        $ledger = DB::table('ledger_entries')
            ->selectRaw('currency, -SUM(amount_minor) AS balance')
            ->where('account', Account::CustomerLiability->value)
            ->groupBy('currency')
            ->pluck('balance', 'currency');

        $orders = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw('order_items.currency AS currency, SUM(order_items.amount_minor) AS outstanding')
            ->whereNull('order_items.settled_at')
            ->whereNotNull('orders.paid_at')
            ->groupBy('order_items.currency')
            ->pluck('outstanding', 'currency');

        $currencies = $ledger->keys()->merge($orders->keys())->unique();
        $breakdown = [];
        $mismatched = 0;

        foreach ($currencies as $currency) {
            $ledgerBalance = (int) $ledger->get($currency, 0);
            $outstanding = (int) $orders->get($currency, 0);
            $delta = $ledgerBalance - $outstanding;

            if ($delta !== 0) {
                $mismatched++;
            }

            $breakdown[] = [
                'currency' => $currency,
                'ledger_liability_minor' => $ledgerBalance,
                'orders_outstanding_minor' => $outstanding,
                'delta_minor' => $delta,
            ];
        }

        return [
            'description' => 'Customer liability balance compared with paid but unsettled items.',
            'count' => $mismatched,
            'by_currency' => $breakdown,
        ];
    }

    /**
     * The stage-2 money identity: paid equals delivered plus refunded plus what
     * is still owed.
     *
     * Both sides are computed independently. The domain side sums order and item
     * rows; the ledger side sums entries by account and reference type. They can
     * only agree if every delivery and every refund was recorded exactly once, so
     * this single check catches a missing refund, a double refund, a delivery
     * that never became revenue, and an order total that drifted from its lines.
     *
     * Amounts are compared per currency so unrelated totals cannot cancel out.
     */
    private function moneyConservation(): array
    {
        $paid = DB::table('orders')
            ->selectRaw('currency, SUM(amount_minor) AS total')
            ->whereNotNull('paid_at')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $items = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw(<<<'SQL'
                order_items.currency AS currency,
                SUM(order_items.amount_minor) AS total,
                SUM(order_items.amount_minor) FILTER (WHERE order_items.status = 'delivered') AS delivered,
                SUM(order_items.amount_minor) FILTER (WHERE order_items.status = 'refunded') AS refunded,
                SUM(order_items.amount_minor) FILTER (WHERE order_items.settled_at IS NULL) AS outstanding
            SQL)
            ->whereNotNull('orders.paid_at')
            ->groupBy('order_items.currency')
            ->get()
            ->keyBy('currency');

        $ledger = DB::table('ledger_entries')
            ->selectRaw(<<<'SQL'
                currency,
                SUM(amount_minor) FILTER (WHERE account = 'cash' AND ref_type = 'payment_event') AS received,
                -SUM(amount_minor) FILTER (WHERE account = 'cash' AND ref_type = 'refund_item') AS refunded,
                -SUM(amount_minor) FILTER (WHERE account = 'revenue') AS revenue
            SQL)
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        $currencies = $paid->keys()
            ->merge($items->keys())
            ->merge($ledger->keys())
            ->unique();

        $breakdown = [];
        $mismatched = 0;

        foreach ($currencies as $currency) {
            $orderTotal = (int) $paid->get($currency, 0);
            $itemRow = $items->get($currency);
            $ledgerRow = $ledger->get($currency);

            $delivered = (int) ($itemRow->delivered ?? 0);
            $refunded = (int) ($itemRow->refunded ?? 0);
            $outstanding = (int) ($itemRow->outstanding ?? 0);

            $deltas = [
                // The order total must equal the sum of the lines it is made of.
                'order_total_vs_items' => $orderTotal - (int) ($itemRow->total ?? 0),
                // Paid equals delivered plus refunded plus still owed.
                'paid_vs_settled_and_outstanding' => $orderTotal - ($delivered + $refunded + $outstanding),
                // The ledger recorded the same payments the orders claim.
                'orders_vs_ledger_received' => $orderTotal - (int) ($ledgerRow->received ?? 0),
                // Every delivered line became revenue, exactly once.
                'delivered_vs_revenue' => $delivered - (int) ($ledgerRow->revenue ?? 0),
                // Every refunded line took cash back out, exactly once.
                'refunded_vs_ledger_refunds' => $refunded - (int) ($ledgerRow->refunded ?? 0),
            ];

            if (array_any($deltas, static fn (int $delta): bool => $delta !== 0)) {
                $mismatched++;
            }

            $breakdown[] = [
                'currency' => $currency,
                'paid_minor' => $orderTotal,
                'delivered_minor' => $delivered,
                'refunded_minor' => $refunded,
                'outstanding_minor' => $outstanding,
                'ledger_received_minor' => (int) ($ledgerRow->received ?? 0),
                'ledger_revenue_minor' => (int) ($ledgerRow->revenue ?? 0),
                'ledger_refunded_minor' => (int) ($ledgerRow->refunded ?? 0),
                'deltas' => $deltas,
            ];
        }

        return [
            'description' => 'Paid money equals delivered plus refunded plus outstanding, in both the domain and the ledger.',
            'count' => $mismatched,
            'by_currency' => $breakdown,
        ];
    }

    /**
     * Reduces a check to a complete count and a limited sample.
     *
     * Count and sum are computed without a limit. Using the truncated sample size
     * would cap monitoring metrics precisely when discrepancy volume is high.
     *
     * @param  callable(): Builder  $query
     * @param  callable(Builder): Collection  $sample
     */
    private function summarise(string $description, callable $query, callable $sample, ?string $amountColumn = null): array
    {
        $count = $query()->count();

        $result = [
            'description' => $description,
            'count' => $count,
        ];

        if ($amountColumn !== null) {
            $result['amount_minor'] = (int) $query()->sum($amountColumn);
        }

        if ($count === 0) {
            return $result + ['items' => [], 'truncated' => false];
        }

        $items = $sample($query());

        return $result + [
            'items' => $items->all(),
            'truncated' => $count > $items->count(),
        ];
    }
}
