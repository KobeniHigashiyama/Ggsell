<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Queries\ShowcaseQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProductController extends Controller
{
    public function index(Request $request, ShowcaseQuery $showcase): JsonResponse
    {
        // Laravel's boolean rule rejects the strings "true" and "false" that
        // naturally arrive through a query string, so normalize before validation.
        if ($request->has('in_stock')) {
            $request->merge([
                'in_stock' => filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:32'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'in_stock' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $showcase->handle(
                type: $validated['type'] ?? null,
                cursor: $validated['cursor'] ?? null,
                limit: isset($validated['limit']) ? (int) $validated['limit'] : null,
                inStockOnly: (bool) ($validated['in_stock'] ?? false),
            );
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Invalid cursor.'], 400);
        }

        return response()->json([
            'data' => $result['items'],
            'next_cursor' => $result['next_cursor'],
        ]);
    }
}
