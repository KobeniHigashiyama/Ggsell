<?php

declare(strict_types=1);

namespace App\Domain\Ops\Recovery;

use App\Domain\Delivery\Actions\CommitDelivery;
use App\Domain\Delivery\Actions\QuarantineUntrustedCode;
use App\Domain\Delivery\Actions\ValidateSupplierCode;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Enums\ViolationKind;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Models\OrphanedCode;
use App\Domain\Delivery\Models\SupplierViolation;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierOutcome;
use App\Domain\Delivery\Suppliers\SupplierRateLimiter;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\Log\DeliveryLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Closes the gap between what a supplier said and what it actually did.
 *
 * Stage 2 requires discrepancies to be sorted out automatically, so this runs on
 * the scheduler and needs no operator. It does three things, in this order,
 * because each one can create work for the next:
 *
 *   1. Asks the supplier's registry about every request it will not resolve
 *      itself: ones it rejected, which are only believed until someone checks,
 *      and ones whose outcome was never learned, which otherwise block their line
 *      from being either delivered or refunded.
 *   2. Delivers or quarantines whatever that turns up.
 *   3. Hands stranded codes back and closes the incident.
 *
 * It ignores the fulfilment run budget, which exists to stop new supplier
 * requests for a hopeless line: a question creates no obligation and has to keep
 * working after the budget is spent. It does not ignore the supplier's rate
 * limit, because a question is still a request, and it yields part of every
 * window to deliveries.
 */
final readonly class AutoResolveDiscrepancies
{
    public function __construct(
        private SupplierClient $client,
        private SupplierRateLimiter $rateLimiter,
        private ValidateSupplierCode $validateCode,
        private QuarantineUntrustedCode $quarantineCode,
        private CommitDelivery $commitDelivery,
    ) {}

    /**
     * @return array{audited: int, recovered: int, quarantined: int, resolved_unknown: int, returned: int, closed: int}
     */
    public function handle(?int $limit = null, ?int $graceSeconds = null): array
    {
        $limit ??= (int) config('ggsell.recovery.batch_size');
        $grace = $graceSeconds ?? (int) config('ggsell.recovery.stuck_after_seconds');

        $audit = $this->auditSupplierClaims($limit, $grace);

        return $audit + [
            'returned' => $this->returnStrandedCodes($limit),
            'closed' => $this->closeSettledViolations($limit),
        ];
    }

    /**
     * Asks the supplier what it really did with the requests it will not resolve.
     *
     * Two kinds of attempt end up here and both are dead ends without this sweep.
     * A rejected attempt is only believed until someone checks. An attempt whose
     * outcome was never resolved blocks its line completely: it cannot be
     * delivered, because nobody knows if a code exists, and it cannot be refunded,
     * because a code might. Once the fulfilment run budget is spent, nothing else
     * in the system ever asks again.
     *
     * The supplier's registry is the only evidence available. A supplier that lies
     * here cannot be caught by this system, which is stated plainly rather than
     * pretended away; what it does mean is that "no record" closes the attempt and
     * frees the line, and "here is the code" either delivers or strands it.
     *
     * @return array{audited: int, recovered: int, quarantined: int, resolved_unknown: int}
     */
    private function auditSupplierClaims(int $limit, int $grace): array
    {
        $attempts = DeliveryAttempt::query()
            ->where(fn (Builder $query) => $query
                // A rejection is audited once.
                ->where(fn (Builder $rejected) => $rejected
                    ->where('status', AttemptStatus::Failed->value)
                    ->whereNull('verified_at'))
                // An unresolved outcome is asked about until it resolves.
                ->orWhereIn('status', [AttemptStatus::Pending->value, AttemptStatus::Unknown->value])
                // A code the audit already found but never placed anywhere. The
                // ordering above makes this window small; this makes it closable
                // rather than permanent, because a consumed key that is neither
                // delivered nor stranded is inventory nobody is looking for.
                ->orWhere(fn (Builder $adrift) => $adrift
                    ->whereNotNull('delivery_attempts.code')
                    ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('deliveries')
                        ->whereColumn('deliveries.code', 'delivery_attempts.code'))
                    ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('orphaned_codes')
                        ->whereColumn('orphaned_codes.code', 'delivery_attempts.code'))))
            // Inclusive: timestamps are stored at second precision, so a strict
            // comparison would silently skip everything within the current second.
            ->where('started_at', '<=', now()->subSeconds($grace))
            ->orderBy('started_at')
            ->limit($limit)
            ->get();

        $recovered = 0;
        $quarantined = 0;
        $resolvedUnknown = 0;
        $audited = 0;

        foreach ($attempts as $attempt) {
            try {
                $outcome = $this->audit($attempt);
            } catch (Throwable $e) {
                // One bad row must not cost the rest of the batch a whole minute.
                DeliveryLog::error('audit.attempt_failed', [
                    'request_id' => $attempt->request_id,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            match ($outcome) {
                'recovered' => $recovered++,
                'quarantined' => $quarantined++,
                'resolved_unknown' => $resolvedUnknown++,
                default => null,
            };

            if ($outcome !== 'skipped') {
                $audited++;
            }
        }

        return [
            'audited' => $audited,
            'recovered' => $recovered,
            'quarantined' => $quarantined,
            'resolved_unknown' => $resolvedUnknown,
        ];
    }

    /**
     * Audits one attempt against the supplier's own registry.
     *
     * @return string skipped | confirmed | resolved_unknown | recovered | quarantined
     */
    private function audit(DeliveryAttempt $attempt): string
    {
        // Verifying is still a request the supplier has to serve, so it comes out
        // of the same allowance as an issue, and yields to deliveries when the
        // window is tight. Without allowance the row waits for the next sweep.
        if (! $this->rateLimiter->tryAcquireForBackgroundWork($attempt->supplier)) {
            return 'skipped';
        }

        $response = $this->client->verify($attempt->supplier, $attempt->request_id);

        if ($response->outcome === SupplierOutcome::Unknown) {
            // The supplier could not be reached. Leave verified_at null so the
            // next sweep asks again rather than recording a false all-clear.
            return 'skipped';
        }

        $wasUnresolved = ! $attempt->status->isResolved();

        if (! $response->isOk() || $response->code === null) {
            $attempt->verified_at = now();
            // The supplier has no record of the request, which is the one answer
            // that proves nothing was issued. For an unresolved attempt this is
            // what unblocks the line: it can now fall back or be refunded.
            $attempt->status = AttemptStatus::Failed;
            $attempt->error_reason ??= $response->reason;
            $attempt->finished_at ??= now();
            $attempt->save();

            if ($wasUnresolved) {
                DeliveryLog::warning('attempt.closed_by_audit', [
                    'request_id' => $attempt->request_id,
                    'supplier' => $attempt->supplier->value,
                    'reason' => $response->reason,
                ]);

                return 'resolved_unknown';
            }

            return 'confirmed';
        }

        $attempt->code ??= $response->code;
        $attempt->reported_sku ??= $response->sku;
        $attempt->save();

        // verified_at is set only once the code has somewhere to be: delivered to
        // its line or recorded as stranded. Marking the attempt audited first
        // would mean a crash in between removes it from this sweep's scope while
        // its key is still consumed and unaccounted for.
        $outcome = $this->settleDiscoveredCode($attempt, (string) $attempt->code, $attempt->reported_sku)
            ? 'recovered'
            : 'quarantined';

        $attempt->verified_at = now();
        $attempt->save();

        return $outcome;
    }

    /**
     * Decides what to do with a code that turned up behind a reported failure.
     *
     * If the line it was issued for is still waiting and the code checks out, the
     * best outcome for everyone is to deliver it: the customer gets what they
     * paid for and no inventory is wasted. Otherwise it is quarantined and handed
     * back.
     *
     * @return bool True when the code was delivered to its own line.
     */
    private function settleDiscoveredCode(DeliveryAttempt $attempt, string $code, ?string $reportedSku): bool
    {
        $item = OrderItem::query()->find($attempt->order_item_id);

        if ($item === null) {
            return false;
        }

        DeliveryLog::warning('supplier.code_found_behind_error', [
            'item_id' => $item->public_id,
            'supplier' => $attempt->supplier->value,
            'request_id' => $attempt->request_id,
        ]);

        $deliverable = ! $item->status->isSettled()
            && ! $item->delivery()->exists()
            && $this->validateCode->handle($item, $code, $reportedSku) === null;

        if ($deliverable) {
            $attempt->status = AttemptStatus::Succeeded;
            $attempt->save();

            if ($this->commitDelivery->handle($item, $attempt, $code)->isDelivered()) {
                return true;
            }
        }

        $this->quarantineCode->handle(
            $item,
            $attempt,
            $code,
            ViolationKind::IssuedAfterError,
            'reported '.($attempt->error_reason ?? 'error').', registry holds a code',
        );

        return false;
    }

    /**
     * Gives codes the core cannot use back to the supplier that issued them.
     *
     * The supplier revokes rather than resells them, which is what turns a
     * stranded code into a closed incident instead of a recurring one.
     */
    private function returnStrandedCodes(int $limit): int
    {
        $orphans = OrphanedCode::query()
            ->whereNull('resolved_at')
            // Oldest attempt first, not oldest row: a code the supplier keeps
            // refusing is pushed to the back below, so it cannot hold up every
            // incident recorded after it.
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $returned = 0;

        foreach ($orphans as $orphan) {
            $supplier = SupplierId::from($orphan->supplier);

            // Decided under the same lock CommitDelivery takes, so a delivery of
            // this code and a decision to hand it back cannot interleave: one of
            // them observes the other's outcome instead of both reading a world
            // in which neither has happened yet.
            $keptForCustomer = DB::transaction(function () use ($orphan): bool {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$orphan->code]);

                if (! $this->belongsToACustomer($orphan->code)) {
                    return false;
                }

                $orphan->forceFill([
                    'resolved_at' => now(),
                    'resolved_by' => 'auto',
                    'resolution' => 'kept: the supplier issued this code to a delivered line as well',
                ])->save();

                return true;
            });

            if ($keptForCustomer) {
                // The supplier sold one key twice. Handing this code back would
                // revoke it, and the customer holding it did nothing wrong: their
                // order is delivered and they would find out only when the code
                // failed to work. The incident is closed as ours to absorb, and
                // the violation record keeps the supplier's part of it.
                DeliveryLog::error('orphaned_code.kept_belongs_to_customer', [
                    'orphaned_code_id' => $orphan->id,
                    'supplier' => $orphan->supplier,
                    'reason' => $orphan->reason,
                ]);

                $returned++;

                continue;
            }

            if (! $this->rateLimiter->tryAcquireForBackgroundWork($supplier)) {
                continue;
            }

            if (! $this->client->returnCode($supplier, $orphan->code, $orphan->reason)) {
                // The next sweep tries again; returning a code is safe to repeat.
                // Touching the row moves it behind incidents that have not been
                // tried yet.
                $orphan->touch();

                continue;
            }

            $orphan->forceFill([
                'resolved_at' => now(),
                'resolved_by' => 'auto',
                'resolution' => 'returned to supplier and revoked',
            ])->save();

            $returned++;

            DeliveryLog::info('orphaned_code.returned', [
                'orphaned_code_id' => $orphan->id,
                'supplier' => $orphan->supplier,
                'reason' => $orphan->reason,
            ]);
        }

        return $returned;
    }

    /**
     * True when this code is the one a customer is holding.
     *
     * Decided from our own data rather than from the reason the orphan was
     * recorded under: several incidents can strand a code, and only the code
     * itself says whether returning it would break a delivery that already
     * happened.
     */
    private function belongsToACustomer(string $code): bool
    {
        return DB::table('deliveries')->where('code', $code)->exists();
    }

    /**
     * Closes violations whose damage has been repaired.
     *
     * A violation stays open while the customer is still owed something or the
     * stranded code is still out there, so the reconciliation report keeps
     * showing real, actionable incidents rather than history.
     */
    private function closeSettledViolations(int $limit): int
    {
        $violations = SupplierViolation::query()
            ->open()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $closed = 0;

        foreach ($violations as $violation) {
            $itemSettled = $violation->order_item_id === null
                || OrderItem::query()->whereKey($violation->order_item_id)->whereNotNull('settled_at')->exists();

            $codeReturned = $violation->code === null
                || ! DB::table('orphaned_codes')
                    ->where('code', $violation->code)
                    ->whereNull('resolved_at')
                    ->exists();

            if (! $itemSettled || ! $codeReturned) {
                continue;
            }

            $violation->forceFill([
                'resolved_at' => now(),
                'resolution' => 'code returned and line settled',
            ])->save();

            $closed++;
        }

        return $closed;
    }
}
