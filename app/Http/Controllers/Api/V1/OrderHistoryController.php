<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\History\Queries\OrderStateAt;
use App\Domain\History\Queries\PeriodTotals;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reading the past.
 *
 * Both endpoints answer from the append-only log rather than from current rows,
 * so an answer about last Tuesday cannot quietly change when something happens
 * today.
 */
class OrderHistoryController extends Controller
{
    public function orderAt(Request $request, string $publicId, OrderStateAt $orderStateAt): JsonResponse
    {
        $validated = $request->validate([
            'at' => ['nullable', 'date'],
        ]);

        $order = Order::query()->where('public_id', $publicId)->firstOrFail();
        $moment = isset($validated['at']) ? Carbon::parse($validated['at']) : now();

        return response()->json(['data' => $orderStateAt->handle($order, $moment)]);
    }

    public function period(Request $request, PeriodTotals $periodTotals): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $result = $periodTotals->handle(
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to']),
        );

        // A period whose two independent records disagree is a discrepancy, not a
        // report, so it answers with a status monitoring can act on.
        return response()->json($result, $result['ledger_check']['matches'] ? 200 : 409);
    }
}
