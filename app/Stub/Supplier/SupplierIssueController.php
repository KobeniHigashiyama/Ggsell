<?php

declare(strict_types=1);

namespace App\Stub\Supplier;

use App\Stub\Supplier\Models\SupplierKey;
use App\Stub\Supplier\Models\SupplierRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Delivery supplier stub.
 *
 * This represents an external service. It owns a separate Postgres schema,
 * knows nothing about core orders or payments, and communicates only over HTTP.
 *
 * Its central contract is that a repeated request_id returns the same code. The
 * primary key on stub.supplier_requests.request_id enforces that guarantee, and
 * it holds in every mode, including the dishonest ones: a retry is always safe.
 *
 * Timeout mode models the classic trap: the key is claimed and the request is
 * recorded before the delay. The supplier has issued a code, but the caller does
 * not know it; retrying with a new request_id would issue another one.
 *
 * Stage 2 adds three ways for the supplier to be actively untrustworthy, so the
 * core has something real to defend against:
 *
 *   duplicate         hands over a code it already gave to another request;
 *   foreign_code      hands over a code from a different product's pool;
 *   error_but_issued  issues the code and then reports a definitive failure.
 */
class SupplierIssueController
{
    public static function overrideKey(string $supplier): string
    {
        return "stub_chaos:{$supplier}";
    }

    public function issue(Request $request, string $supplier): JsonResponse
    {
        $validated = $this->validate($request, $supplier);

        if (! $this->admitRequest($supplier)) {
            return $this->rateLimited();
        }

        // Serve known requests from the registry before applying chaos because
        // their work is already complete and must not run twice.
        $known = SupplierRequest::query()->find($validated['request_id']);

        if ($known !== null) {
            return $this->success($known->request_id, $known->code, $known->sku, replay: true);
        }

        $mode = $this->resolveMode($request, $supplier);

        if ($mode === ChaosMode::Error) {
            // A deterministic rejection does not consume a key and permits fallback.
            return response()->json(['status' => 'error', 'reason' => 'supplier_error'], 503);
        }

        if ($mode === ChaosMode::OutOfStock) {
            return response()->json(['status' => 'error', 'reason' => 'out_of_stock'], 409);
        }

        if ($mode === ChaosMode::Duplicate && ($duplicate = $this->reissueExistingCode($supplier, $validated)) !== null) {
            return $this->success($validated['request_id'], $duplicate['code'], $duplicate['sku'], replay: false);
        }

        if ($mode === ChaosMode::ForeignCode && ($foreign = $this->issueFromAnotherPool($supplier, $validated)) !== null) {
            return $this->success($validated['request_id'], $foreign['code'], $foreign['sku'], replay: false);
        }

        $claim = $this->claimKey($supplier, $validated['sku'], $validated['request_id'], $validated['order_id']);

        if ($claim === null) {
            return response()->json(['status' => 'error', 'reason' => 'out_of_stock'], 409);
        }

        if ($mode === ChaosMode::Timeout) {
            // The key is already committed; now hang so the response is lost.
            $this->hang($supplier);
        }

        if ($mode === ChaosMode::ErrorButIssued) {
            // The key is committed and the registry knows about it, yet the caller
            // is told the request failed. Only asking again, or verifying the
            // request id, reveals that a code exists.
            return response()->json(['status' => 'error', 'reason' => 'supplier_error'], 503);
        }

        return $this->success($validated['request_id'], $claim, $validated['sku'], replay: false);
    }

    /**
     * Confirms what this supplier did with a request id.
     *
     * The honest half of an untrustworthy supplier: it may hand over the wrong
     * code, but its own books are queryable. This is what lets the core discover
     * a code that was issued behind a reported failure.
     */
    public function verify(string $supplier, string $requestId): JsonResponse
    {
        if (! $this->admitRequest($supplier)) {
            return $this->rateLimited();
        }

        $known = SupplierRequest::query()
            ->where('request_id', $requestId)
            ->where('supplier', $supplier)
            ->first();

        if ($known === null) {
            return response()->json(['status' => 'error', 'reason' => 'unknown_request'], 404);
        }

        return $this->success($known->request_id, $known->code, $known->sku, replay: true);
    }

    /**
     * Takes a code back.
     *
     * A returned code is revoked rather than made available again: it may already
     * be in somebody's hands, so putting it back in the pool would repeat the
     * incident instead of closing it. Idempotent, because recovery retries.
     */
    public function returnCode(Request $request, string $supplier): JsonResponse
    {
        if (! $this->admitRequest($supplier)) {
            return $this->rateLimited();
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $updated = SupplierKey::query()
            ->where('supplier', $supplier)
            ->where('code', $validated['code'])
            ->whereNull('returned_at')
            ->update(['status' => 'revoked', 'returned_at' => now()]);

        return response()->json([
            'status' => 'ok',
            'code' => $validated['code'],
            'revoked' => $updated > 0,
        ]);
    }

    /**
     * The supplier's own opinion of how much traffic it agreed to take.
     *
     * The core is supposed to pace itself so this never fires; when it does, it is
     * evidence rather than something to retry around. Every endpoint is counted,
     * not only issue: a real supplier limits by credential, and an audit sweep
     * that did not count would let the core exceed the agreed rate while both
     * sides reported agreement.
     */
    private function rateLimited(): JsonResponse
    {
        return response()->json(
            ['status' => 'error', 'reason' => 'rate_limited'],
            429,
            ['Retry-After' => (string) (60 - (int) now()->second)],
        );
    }

    /**
     * Counts this request against the supplier's per-minute allowance.
     *
     * One statement: the insert-or-increment only succeeds while the window still
     * has room, so concurrent requests cannot both take the last slot.
     */
    private function admitRequest(string $supplier): bool
    {
        $limit = (int) config("ggsell.suppliers.rate_limit.{$supplier}", 30);

        $admitted = DB::select(<<<'SQL'
            INSERT INTO stub.rate_windows (supplier, window_start, used)
            VALUES (?, date_trunc('minute', now()), 1)
            ON CONFLICT (supplier, window_start) DO UPDATE
            SET used = stub.rate_windows.used + 1
            WHERE stub.rate_windows.used < ?
            RETURNING used
        SQL, [$supplier, $limit]);

        if ($admitted !== []) {
            return true;
        }

        DB::update(<<<'SQL'
            UPDATE stub.rate_windows
            SET rejected = rejected + 1
            WHERE supplier = ? AND window_start = date_trunc('minute', now())
        SQL, [$supplier]);

        return false;
    }

    /**
     * What the supplier has seen this minute.
     *
     * Exposed over HTTP rather than read from the stub's schema, so a scenario
     * can check the core's restraint against the supplier's own books without
     * anyone reaching across the boundary.
     */
    public function rate(string $supplier): JsonResponse
    {
        $window = DB::selectOne(<<<'SQL'
            SELECT used, rejected
            FROM stub.rate_windows
            WHERE supplier = ? AND window_start = date_trunc('minute', now())
        SQL, [$supplier]);

        return response()->json([
            'supplier' => $supplier,
            'limit_per_minute' => (int) config("ggsell.suppliers.rate_limit.{$supplier}", 30),
            'used' => (int) ($window->used ?? 0),
            'rejected' => (int) ($window->rejected ?? 0),
        ]);
    }

    public function stock(string $supplier): JsonResponse
    {
        $rows = SupplierKey::query()
            ->selectRaw('sku, count(*) as available')
            ->where('supplier', $supplier)
            ->where('status', 'available')
            ->groupBy('sku')
            ->pluck('available', 'sku');

        return response()->json(['supplier' => $supplier, 'stock' => $rows]);
    }

    /**
     * Chaos: hand over a code this supplier already gave to someone else.
     *
     * The duplicate row is flagged, which is what lets the stub keep its honest
     * uniqueness guarantee for every other request.
     *
     * @param  array{request_id: string, sku: string, order_id: string}  $validated
     * @return array{code: string, sku: string}|null Null when nothing has been issued yet to duplicate.
     */
    private function reissueExistingCode(string $supplier, array $validated): ?array
    {
        $existing = SupplierKey::query()
            ->where('supplier', $supplier)
            ->where('sku', $validated['sku'])
            ->where('status', 'issued')
            ->orderBy('id')
            ->first();

        if ($existing === null) {
            return null;
        }

        SupplierRequest::create([
            'request_id' => $validated['request_id'],
            'supplier' => $supplier,
            'sku' => $existing->sku,
            'order_ref' => $validated['order_id'],
            'code' => $existing->code,
            'key_id' => $existing->id,
            'chaos' => true,
        ]);

        return ['code' => $existing->code, 'sku' => $existing->sku];
    }

    /**
     * Chaos: hand over a code from a different product's pool.
     *
     * The response still reports the code's real SKU, so this is a supplier that
     * grabbed the wrong box rather than one that lies about what it sent. The
     * mismatch is what the core checks.
     *
     * @param  array{request_id: string, sku: string, order_id: string}  $validated
     * @return array{code: string, sku: string}|null
     */
    private function issueFromAnotherPool(string $supplier, array $validated): ?array
    {
        return DB::transaction(function () use ($supplier, $validated): ?array {
            $key = SupplierKey::query()
                ->where('supplier', $supplier)
                ->where('sku', '!=', $validated['sku'])
                ->where('status', 'available')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($key === null) {
                return null;
            }

            $key->update([
                'status' => 'issued',
                'request_id' => $validated['request_id'],
                'claimed_at' => now(),
            ]);

            SupplierRequest::create([
                'request_id' => $validated['request_id'],
                'supplier' => $supplier,
                'sku' => $key->sku,
                'order_ref' => $validated['order_id'],
                'code' => $key->code,
                'key_id' => $key->id,
                'chaos' => true,
            ]);

            return ['code' => $key->code, 'sku' => $key->sku];
        });
    }

    /**
     * Atomically claim a key.
     *
     * FOR UPDATE SKIP LOCKED lets concurrent deliveries claim different free
     * keys instead of queuing behind the first pool row.
     *
     * @return string|null claimed code, or null when no key is available
     */
    private function claimKey(string $supplier, string $sku, string $requestId, string $orderRef): ?string
    {
        try {
            return DB::transaction(function () use ($supplier, $sku, $requestId, $orderRef): ?string {
                $key = SupplierKey::query()
                    ->where('supplier', $supplier)
                    ->where('sku', $sku)
                    ->where('status', 'available')
                    ->orderBy('id')
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if ($key === null) {
                    return null;
                }

                $key->update([
                    'status' => 'issued',
                    'request_id' => $requestId,
                    'claimed_at' => now(),
                ]);

                SupplierRequest::create([
                    'request_id' => $requestId,
                    'supplier' => $supplier,
                    'sku' => $sku,
                    'order_ref' => $orderRef,
                    'code' => $key->code,
                    'key_id' => $key->id,
                ]);

                return $key->code;
            });
        } catch (UniqueConstraintViolationException) {
            // Another concurrent request with the same request_id won. Return its
            // result because the contract requires both calls to yield one code.
            return SupplierRequest::query()->find($requestId)?->code;
        }
    }

    /**
     * @return array{request_id: string, sku: string, order_id: string}
     */
    private function validate(Request $request, string $supplier): array
    {
        $request->merge(['supplier' => $supplier]);

        return $request->validate([
            'supplier' => ['required', Rule::in(array_keys(config('ggsell.stubs')))],
            'request_id' => ['required', 'string', 'max:80'],
            'sku' => ['required', 'string', 'max:64'],
            'order_id' => ['required', 'string', 'max:40'],
        ]);
    }

    /**
     * Resolve failure mode from most explicit to most general.
     *
     * Direct stub tests use the header. Live-stack scenarios use a shared cache
     * switch so a command can change every process without restarting containers.
     * Without either override, the stub fails randomly as required by the assignment.
     */
    private function resolveMode(Request $request, string $supplier): ChaosMode
    {
        $forced = $request->header('X-Chaos-Mode');

        if ($forced !== null && $forced !== '') {
            return ChaosMode::tryFrom($forced) ?? ChaosMode::Ok;
        }

        $override = Cache::get(self::overrideKey($supplier));

        if (is_string($override) && ($mode = ChaosMode::tryFrom($override)) !== null) {
            return $mode;
        }

        $config = config("ggsell.stubs.{$supplier}", []);
        $roll = mt_rand() / mt_getrandmax();

        return match (true) {
            $roll < (float) ($config['error_rate'] ?? 0) => ChaosMode::Error,
            $roll < (float) ($config['error_rate'] ?? 0) + (float) ($config['timeout_rate'] ?? 0) => ChaosMode::Timeout,
            default => ChaosMode::Ok,
        };
    }

    private function hang(string $supplier): void
    {
        $seconds = (float) config("ggsell.stubs.{$supplier}.hang_seconds", 6);

        usleep((int) ($seconds * 1_000_000));
    }

    private function success(string $requestId, string $code, string $sku, bool $replay): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'request_id' => $requestId,
            'code' => $code,
            // The SKU the code actually belongs to. The core compares it with what
            // it ordered, which is how a code from the wrong pool is caught.
            'sku' => $sku,
        ], 200, $replay ? ['X-Idempotent-Replay' => 'true'] : []);
    }
}
