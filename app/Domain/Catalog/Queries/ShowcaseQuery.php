<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Hot-path storefront query (stage 5).
 *
 * Two decisions keep it fast with tens of thousands of SKUs:
 *
 * 1. Stock comes from the product_stock counter rather than COUNT(*) over the
 *    key pool. Aggregating hundreds of thousands of rows would require a
 *    sequential scan and hash aggregate for every storefront request.
 *
 * 2. Keyset pagination is used instead of OFFSET. OFFSET makes PostgreSQL read
 *    and discard all skipped rows, while keyset pagination has a stable cost.
 *
 * The (sort_rank, sku) order matches the partial covering index, allowing
 * PostgreSQL to read rows in order without a separate sort step.
 */
final readonly class ShowcaseQuery
{
    /**
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function handle(?string $type = null, ?string $cursor = null, ?int $limit = null, bool $inStockOnly = false): array
    {
        $limit = min(
            $limit ?? (int) config('ggsell.showcase.page_size'),
            (int) config('ggsell.showcase.max_page_size'),
        );

        $query = DB::table('products as p')
            ->join('product_stock as s', 's.sku', '=', 'p.sku')
            ->select([
                'p.sku', 'p.name', 'p.type', 'p.price_minor',
                'p.currency', 'p.image', 'p.sort_rank', 's.available_count',
            ])
            ->where('p.is_active', true)
            ->orderBy('p.sort_rank')
            ->orderBy('p.sku')
        // Fetch one extra row to detect the next page without a separate COUNT.
            ->limit($limit + 1);

        if ($type !== null) {
            $query->where('p.type', $type);
        }

        if ($inStockOnly) {
            // Filtering by the products flag lets the partial index satisfy the
            // predicate and LIMIT with a range scan instead of post-filtering.
            $query->where('p.in_stock', true);
        }

        if ($cursor !== null) {
            [$rank, $sku] = $this->decodeCursor($cursor);

            // Tuple comparison maps to a single index range scan.
            $query->whereRaw('(p.sort_rank, p.sku) > (?, ?)', [$rank, $sku]);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit);

        $last = $items->last();

        return [
            'items' => $items->map(fn (object $row): array => [
                'sku' => $row->sku,
                'name' => $row->name,
                'type' => $row->type,
                'price' => $row->price_minor / 100,
                'price_minor' => (int) $row->price_minor,
                'currency' => $row->currency,
                'image' => $row->image,
                'available' => (int) $row->available_count,
                'in_stock' => $row->available_count > 0,
            ])->values()->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->encodeCursor((int) $last->sort_rank, $last->sku)
                : null,
        ];
    }

    private function encodeCursor(int $rank, string $sku): string
    {
        return rtrim(strtr(base64_encode($rank.'|'.$sku), '+/', '-_'), '=');
    }

    /**
     * A malformed cursor is a caller error, not a request for the first page.
     *
     * @return array{0: int, 1: string}
     */
    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || ! str_contains($decoded, '|')) {
            throw new InvalidArgumentException('Malformed cursor.');
        }

        [$rank, $sku] = explode('|', $decoded, 2);

        if ($sku === '' || ! ctype_digit(ltrim($rank, '-'))) {
            throw new InvalidArgumentException('Malformed cursor.');
        }

        return [(int) $rank, $sku];
    }
}
