<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\History\Actions\RecordOrderEvent;
use App\Domain\History\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\ProductUnavailable;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payments\Actions\ReplayPendingEvents;
use App\Support\Log\PaymentLog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Creates an order from one or more catalog lines.
 *
 * Stock is intentionally not reserved here. The supplier owns the key pool, so
 * a reservation would require distributed locking and timeout-based release for
 * mostly unpaid orders. Shortages are detected during delivery and handled as
 * the recoverable out_of_stock outcome defined by the contract.
 *
 * A requested quantity is expanded into one row per unit. Every unit needs its
 * own code, so treating it as its own item is what lets two of three units be
 * delivered and the third refunded without special cases.
 */
final readonly class CreateOrder
{
    /** Guard against an order so large that one payment cannot be settled sanely. */
    private const MAX_UNITS = 50;

    public function __construct(
        private ReplayPendingEvents $replayPendingEvents,
        private RecordOrderEvent $recordEvent,
    ) {}

    /**
     * @param  list<array{sku: string, quantity?: int}>  $lines
     */
    public function handle(array $lines, ?string $customerEmail = null): Order
    {
        $order = DB::transaction(function () use ($lines, $customerEmail): Order {
            $units = $this->expandToUnits($lines);

            $order = Order::create([
                'public_id' => Order::newPublicId(),
                // The total is the sum of frozen unit prices, so later catalog
                // changes cannot alter an amount already presented for payment.
                'amount_minor' => array_sum(array_column($units, 'amount_minor')),
                'currency' => $units[0]['currency'],
                'status' => OrderStatus::Created,
                'customer_email' => $customerEmail,
            ]);

            $now = now();

            // Identifiers are minted before the insert so the log can carry them.
            // Without them the composition of an order could only be read from the
            // live rows, and a point-in-time answer would silently mix history
            // with the present.
            $rows = array_map(static fn (array $unit): array => $unit + [
                'order_id' => $order->id,
                'public_id' => OrderItem::newPublicId(),
                'status' => OrderItemStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $units);

            OrderItem::insert($rows);

            $this->recordEvent->handle(
                type: OrderEventType::OrderCreated,
                orderId: $order->id,
                orderItemId: null,
                payload: [
                    'amount_minor' => $order->amount_minor,
                    'currency' => $order->currency,
                    'items' => array_map(static fn (array $row): array => [
                        'item_id' => $row['public_id'],
                        'position' => $row['position'],
                        'sku' => $row['sku'],
                        'amount_minor' => $row['amount_minor'],
                    ], $rows),
                ],
                refType: 'order',
                refId: (string) $order->id,
            );

            return $order;
        });

        PaymentLog::info('order.created', [
            'order_id' => $order->public_id,
            'items' => $order->items()->count(),
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
        ]);

        // A webhook may arrive before the order. Replay pending events for
        // this public ID immediately instead of waiting for the scheduler.
        // A replay failure must not turn a committed purchase into a 500;
        // the scheduled sweep will retry the still-pending event.
        try {
            $this->replayPendingEvents->forOrder($order->public_id);
        } catch (\Throwable $e) {
            PaymentLog::error('order.pending_replay_failed', [
                'order_id' => $order->public_id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $order;
    }

    /**
     * Resolves catalog lines into one row per deliverable unit.
     *
     * Products are read in a single query so an order with twenty lines costs one
     * round trip, and the price used is the one read inside this transaction.
     *
     * @param  list<array{sku: string, quantity?: int}>  $lines
     * @return list<array{position: int, sku: string, amount_minor: int, currency: string}>
     */
    private function expandToUnits(array $lines): array
    {
        if ($lines === []) {
            throw new DomainException('An order needs at least one item.');
        }

        $products = Product::query()
            ->whereIn('sku', array_column($lines, 'sku'))
            ->where('is_active', true)
            ->get()
            ->keyBy('sku');

        $units = [];
        $position = 0;

        foreach ($lines as $line) {
            $product = $products->get($line['sku']);

            if ($product === null) {
                throw ProductUnavailable::sku($line['sku']);
            }

            for ($unit = 0; $unit < ($line['quantity'] ?? 1); $unit++) {
                $units[] = [
                    'position' => ++$position,
                    'sku' => $product->sku,
                    'amount_minor' => $product->price_minor,
                    'currency' => $product->currency,
                ];
            }
        }

        if (count($units) > self::MAX_UNITS) {
            throw new DomainException(sprintf('An order cannot exceed %d units.', self::MAX_UNITS));
        }

        // One payment carries one amount in one currency. Mixing them would make
        // the payment contract and the ledger currency checks unenforceable.
        if (count(array_unique(array_column($units, 'currency'))) > 1) {
            throw new DomainException('All items of an order must share one currency.');
        }

        return $units;
    }
}
