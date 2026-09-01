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
 * primary key on stub.supplier_requests.request_id enforces that guarantee.
 *
 * Timeout mode models the real trap: the key is claimed and the request is
 * recorded before the delay. The supplier has issued a code, but the caller
 * does not know it; retrying with a new request_id would issue another one.
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

        // Serve known requests from the registry before applying chaos because
        // their work is already complete and must not run twice.
        $known = SupplierRequest::query()->find($validated['request_id']);

        if ($known !== null) {
            return $this->success($known->request_id, $known->code, replay: true);
        }

        $mode = $this->resolveMode($request, $supplier);

        if ($mode === ChaosMode::Error) {
            // A deterministic rejection does not consume a key and permits fallback.
            return response()->json(['status' => 'error', 'reason' => 'supplier_error'], 503);
        }

        if ($mode === ChaosMode::OutOfStock) {
            return response()->json(['status' => 'error', 'reason' => 'out_of_stock'], 409);
        }

        $claim = $this->claimKey($supplier, $validated['sku'], $validated['request_id'], $validated['order_id']);

        if ($claim === null) {
            return response()->json(['status' => 'error', 'reason' => 'out_of_stock'], 409);
        }

        if ($mode === ChaosMode::Timeout) {
            // The key is already committed; now hang so the response is lost.
            $this->hang($supplier);
        }

        return $this->success($validated['request_id'], $claim, replay: false);
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

    private function success(string $requestId, string $code, bool $replay): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'request_id' => $requestId,
            'code' => $code,
        ], 200, $replay ? ['X-Idempotent-Replay' => 'true'] : []);
    }
}
