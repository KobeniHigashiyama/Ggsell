<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ops\Reconciliation\ReconciliationReport;
use App\Domain\Ops\Recovery\ResolveOrphanedCode;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Http\Controllers\Controller;
use App\Jobs\FulfilOrderJob;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index(Request $request, ReconciliationReport $report): JsonResponse
    {
        $validated = $request->validate([
            'grace_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $result = $report->build(
            isset($validated['grace_seconds']) ? (int) $validated['grace_seconds'] : null,
        );

        // Return 200 when healthy and 409 on discrepancies so monitoring does
        // not need to parse the response body.
        return response()->json(
            $result + ['healthy' => $report->isHealthy($result)],
            $report->isHealthy($result) ? 200 : 409,
        );
    }

    /**
     * Mark an orphaned code as resolved.
     *
     * Reconciliation needs a resolution path. Otherwise resolved discrepancies
     * keep the report red and conceal new incidents in permanent noise.
     */
    public function resolveOrphan(Request $request, int $id, ResolveOrphanedCode $resolveOrphanedCode): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => ['required', 'string', 'max:255'],
            'resolved_by' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $orphan = $resolveOrphanedCode->handle(
                $id,
                $validated['resolution'],
                $validated['resolved_by'] ?? 'ops',
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'orphaned_code_id' => $orphan->id,
            'resolved_at' => $orphan->resolved_at?->toIso8601String(),
        ]);
    }

    /**
     * Manually retry order fulfilment.
     *
     * Uses the same job as automatic delivery. A separate manual path would
     * drift from the primary one and duplicate deliveries exactly when someone
     * reaches for it. The run budget is reset: it exists to stop a hopeless
     * order from cycling in the background, not to lock a human out.
     */
    public function redeliver(string $publicId): JsonResponse
    {
        $order = Order::query()->where('public_id', $publicId)->firstOrFail();

        // Reset the automatic-run budget on the lines that still owe a code. It
        // prevents endless background work, not manual intervention; without a
        // reset recovery would require a direct database edit.
        $requeued = OrderItem::query()
            ->where('order_id', $order->id)
            ->unsettled()
            ->update(['fulfilment_runs' => 0, 'updated_at' => now()]);

        FulfilOrderJob::dispatch($order->id);

        return response()->json([
            'order_id' => $order->public_id,
            'status' => $order->status->value,
            'items_requeued' => $requeued,
            'queued' => true,
        ], 202);
    }
}
