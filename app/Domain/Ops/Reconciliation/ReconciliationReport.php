<?php

declare(strict_types=1);

namespace App\Domain\Ops\Reconciliation;

use App\Domain\Ledger\Account;
use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation report (stage 4).
 *
 * Compares independent representations of the same business state: payment
 * events, order state, deliveries, and the ledger. Every health check is empty
 * in a consistent system, so any result requires investigation.
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
                    'ledger_imbalance' => $this->ledgerImbalance(),
                    'liability_mismatch' => $this->liabilityMismatch(),
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
        $query = fn (): Builder => DB::table('orders')
            ->leftJoin('deliveries', 'deliveries.order_id', '=', 'orders.id')
            ->whereNull('deliveries.id')
            ->whereIn('orders.status', [
                OrderStatus::Paid->value,
                OrderStatus::Delivering->value,
                OrderStatus::OutOfStock->value,
                OrderStatus::DeliveryFailed->value,
            ])
            ->where('orders.paid_at', '<', $threshold);

        return $this->summarise(
            'Деньги получены, товар не выдан.',
            $query,
            fn (Builder $q): Collection => $q->orderBy('orders.paid_at')
                ->limit(self::SAMPLE_SIZE)
                ->get([
                    'orders.public_id', 'orders.status', 'orders.sku',
                    'orders.amount_minor', 'orders.paid_at', 'orders.failure_reason',
                    'orders.fulfilment_runs',
                ]),
            amountColumn: 'orders.amount_minor',
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
            'Товар выдан по заказу без подтверждённой оплаты.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id', 'orders.status', 'deliveries.delivered_at',
            ]),
        );
    }

    /**
     * Delivery exists while the order is not in its final status.
     *
     * The customer owns the product but the API hides its code behind the status.
     * CommitDelivery should make this state unreachable; this check guards that
     * invariant against future changes or direct database writes.
     */
    private function deliveredNotSettled(): array
    {
        $query = fn (): Builder => DB::table('deliveries')
            ->join('orders', 'orders.id', '=', 'deliveries.order_id')
            ->where('orders.status', '!=', OrderStatus::Delivered->value);

        return $this->summarise(
            'Выдача записана, но заказ не переведён в delivered.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id', 'orders.status', 'deliveries.delivered_at',
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
            'description' => 'По заказу прошло больше одного успешного платежа (информационно).',
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
            'Платёж получен, но не применён к заказу (несовпадение суммы или отсутствующий заказ).',
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
            'Отказ платежа по заказу, деньги за который уже были получены.',
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
            ->join('orders', 'orders.id', '=', 'delivery_attempts.order_id')
            ->where('delivery_attempts.status', 'succeeded')
            ->whereNotNull('delivery_attempts.code')
            ->where('delivery_attempts.started_at', '<', $threshold)
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('deliveries')
                ->whereColumn('deliveries.order_id', 'delivery_attempts.order_id')
                ->whereColumn('deliveries.code', 'delivery_attempts.code'))
        // Exclude codes already recorded as orphaned so one incident appears in
        // exactly one check.
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw('1'))
                ->from('orphaned_codes')
                ->whereColumn('orphaned_codes.order_id', 'delivery_attempts.order_id')
                ->whereColumn('orphaned_codes.code', 'delivery_attempts.code'));

        return $this->summarise(
            'Поставщик выдал код, но выдача не зафиксирована.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id', 'delivery_attempts.request_id',
                'delivery_attempts.supplier', 'delivery_attempts.started_at',
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
            ->join('orders', 'orders.id', '=', 'delivery_attempts.order_id')
            ->leftJoin('deliveries', 'deliveries.order_id', '=', 'delivery_attempts.order_id')
            ->whereNull('deliveries.id')
            ->whereIn('delivery_attempts.status', ['pending', 'unknown'])
            ->where('delivery_attempts.started_at', '<', $threshold);

        return $this->summarise(
            'Обращение к поставщику без выясненного исхода.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id', 'delivery_attempts.request_id', 'delivery_attempts.supplier',
                'delivery_attempts.status', 'delivery_attempts.attempt_no', 'delivery_attempts.started_at',
            ]),
        );
    }

    private function orphanedCodes(): array
    {
        $query = fn (): Builder => DB::table('orphaned_codes')
            ->join('orders', 'orders.id', '=', 'orphaned_codes.order_id')
            ->whereNull('orphaned_codes.resolved_at');

        return $this->summarise(
            'Код получен от поставщика, но не привязан к заказу.',
            $query,
            fn (Builder $q): Collection => $q->limit(self::SAMPLE_SIZE)->get([
                'orders.public_id', 'orphaned_codes.supplier',
                'orphaned_codes.reason', 'orphaned_codes.created_at',
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
            'description' => 'Проводки, не сходящиеся в ноль.',
            'count' => $rows->count(),
            'items' => $rows->all(),
        ];
    }

    /**
     * Customer liability balance compared with actual order state.
     *
     * Customer liability must exactly match the total value of paid, undelivered
     * orders. This connects the ledger to domain state and exposes both missing
     * and duplicate exactly-once operations even when entries balance internally.
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

        $orders = DB::table('orders')
            ->leftJoin('deliveries', 'deliveries.order_id', '=', 'orders.id')
            ->selectRaw('orders.currency AS currency, SUM(orders.amount_minor) AS outstanding')
            ->whereNull('deliveries.id')
            ->whereNotNull('orders.paid_at')
            ->groupBy('orders.currency')
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
            'description' => 'Сальдо обязательств перед покупателями против суммы оплаченных, но не выданных заказов.',
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
