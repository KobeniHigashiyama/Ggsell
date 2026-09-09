<?php

declare(strict_types=1);

namespace App\Stub\Payment;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment gateway stub.
 *
 * This represents an external service: it owns a separate PostgreSQL schema,
 * knows nothing about orders or items beyond the references it is given, and is
 * reachable only over HTTP.
 *
 * Its contract mirrors the supplier's. A repeated refund_request_id returns the
 * original reference instead of moving money again, enforced by the primary key
 * on stub.refund_requests.
 *
 * Timeout mode records the refund before hanging, which is the trap the core has
 * to survive: the money left the account but the caller never learned it.
 */
class RefundController
{
    public static function overrideKey(): string
    {
        return 'payment_stub_chaos';
    }

    public function refund(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refund_request_id' => ['required', 'string', 'max:80'],
            'order_id' => ['required', 'string', 'max:40'],
            'item_id' => ['required', 'string', 'max:40'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        // Serve known requests from the registry before applying chaos: their
        // money already moved and must not move twice.
        $known = DB::table('stub.refund_requests')
            ->where('request_id', $validated['refund_request_id'])
            ->first();

        if ($known !== null) {
            return $this->success($known->reference, replay: true);
        }

        $mode = $this->resolveMode($request);

        if ($mode === PaymentChaosMode::Error) {
            // A deterministic rejection moves no money, so the caller may retry.
            return response()->json(['status' => 'error', 'reason' => 'refund_declined'], 503);
        }

        $reference = 'rfd_'.Str::lower((string) Str::ulid());

        try {
            DB::table('stub.refund_requests')->insert([
                'request_id' => $validated['refund_request_id'],
                'order_ref' => $validated['order_id'],
                'item_ref' => $validated['item_id'],
                'amount_minor' => $validated['amount_minor'],
                'currency' => $validated['currency'],
                'reference' => $reference,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent call with the same request id won. Return its result,
            // because the contract requires both callers to see one refund.
            $reference = (string) DB::table('stub.refund_requests')
                ->where('request_id', $validated['refund_request_id'])
                ->value('reference');

            return $this->success($reference, replay: true);
        }

        if ($mode === PaymentChaosMode::Timeout) {
            // The refund is already committed; now hang so the response is lost.
            usleep((int) ((float) config('ggsell.payments.stub.hang_seconds', 6) * 1_000_000));
        }

        return $this->success($reference, replay: false);
    }

    /**
     * Resolve failure mode from most explicit to most general.
     *
     * Direct tests use the header; live-stack scenarios use a shared cache switch
     * so a command can change every process without restarting containers.
     */
    private function resolveMode(Request $request): PaymentChaosMode
    {
        $forced = $request->header('X-Chaos-Mode');

        if ($forced !== null && $forced !== '') {
            return PaymentChaosMode::tryFrom($forced) ?? PaymentChaosMode::Ok;
        }

        $override = Cache::get(self::overrideKey());

        if (is_string($override) && ($mode = PaymentChaosMode::tryFrom($override)) !== null) {
            return $mode;
        }

        $config = (array) config('ggsell.payments.stub', []);
        $roll = mt_rand() / mt_getrandmax();

        return match (true) {
            $roll < (float) ($config['error_rate'] ?? 0) => PaymentChaosMode::Error,
            $roll < (float) ($config['error_rate'] ?? 0) + (float) ($config['timeout_rate'] ?? 0) => PaymentChaosMode::Timeout,
            default => PaymentChaosMode::Ok,
        };
    }

    private function success(string $reference, bool $replay): JsonResponse
    {
        return response()->json(
            ['status' => 'ok', 'reference' => $reference],
            200,
            $replay ? ['X-Idempotent-Replay' => 'true'] : [],
        );
    }
}
