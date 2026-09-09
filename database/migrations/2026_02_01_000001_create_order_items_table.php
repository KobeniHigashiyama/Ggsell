<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 moves the unit of fulfilment from the order to the order item.
 *
 * One row is one deliverable unit and therefore one code. A request for three
 * units creates three rows, which keeps every exactly-once guarantee expressible
 * as a unique index on a single column instead of a composite counter.
 *
 * orders.sku and orders.quantity are removed after the backfill: an order that
 * spans several suppliers has no single SKU, and leaving a denormalized copy
 * would create a second, silently diverging source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');

            // Public identifier. Item-level operations (refunds, ops endpoints,
            // supplier request IDs) address items, so they need a safe identifier.
            $table->string('public_id', 40)->unique();

            // Stable ordinal within the order. Makes API output deterministic and
            // gives support staff a short way to name one line of an order.
            $table->unsignedSmallInteger('position');

            $table->string('sku', 64);

            // Unit price frozen at purchase time, mirroring stage 1. The order
            // total is the sum of its items.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('status', 24);

            // Supplier that actually delivered, denormalized for reporting.
            $table->string('supplier', 16)->nullable();
            $table->string('failure_reason', 64)->nullable();

            // The run budget is per item: one hopeless item must not stop the
            // remaining items of the same order from being retried.
            $table->unsignedSmallInteger('fulfilment_runs')->default(0);

            $table->timestamp('delivered_at')->nullable();

            // Set when the item reaches a terminal money state, delivered or
            // refunded. Anything with settled_at IS NULL still owes the customer.
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('sku')->references('sku')->on('products')->restrictOnDelete();
            $table->unique(['order_id', 'position']);
            $table->index('order_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amount_positive CHECK (amount_minor > 0)');

        // Working set for delivery recovery and reconciliation. It stays small
        // because settled items leave the index.
        DB::statement(<<<'SQL'
            CREATE INDEX order_items_unsettled_idx
                ON order_items (status, updated_at)
                WHERE status IN ('pending', 'delivering', 'out_of_stock', 'delivery_failed')
        SQL);

        $this->backfillFromOrders();

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['sku']);
            $table->dropColumn(['sku', 'quantity']);
        });
    }

    /**
     * Converts every stage-1 order into an equivalent single item.
     *
     * The status mapping is deliberately conservative: only states that describe
     * delivery progress carry over. A payment-level state (created, paid,
     * payment_failed) leaves the item pending, because no delivery work started.
     */
    private function backfillFromOrders(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO order_items (
                order_id, public_id, position, sku, amount_minor, currency, status,
                supplier, failure_reason, fulfilment_runs, delivered_at, settled_at,
                created_at, updated_at
            )
            SELECT
                o.id,
                'itm_' || replace(gen_random_uuid()::text, '-', ''),
                units.n,
                o.sku,
                o.amount_minor / o.quantity,
                o.currency,
                CASE o.status
                    WHEN 'delivered'       THEN 'delivered'
                    WHEN 'delivering'      THEN 'delivering'
                    WHEN 'out_of_stock'    THEN 'out_of_stock'
                    WHEN 'delivery_failed' THEN 'delivery_failed'
                    ELSE 'pending'
                END,
                d.supplier,
                o.failure_reason,
                o.fulfilment_runs,
                o.delivered_at,
                CASE WHEN o.status = 'delivered' THEN o.delivered_at END,
                o.created_at,
                o.updated_at
            FROM orders o
            CROSS JOIN LATERAL generate_series(1, o.quantity) AS units(n)
            LEFT JOIN deliveries d ON d.order_id = o.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable while the backfill runs; the stage-1 shape is restored
            // below once every order has a SKU again.
            $table->string('sku', 64)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
        });

        // Collapse each order back to its first line. Multi-supplier orders cannot
        // be represented by the stage-1 schema, so this is lossy by construction.
        DB::statement(<<<'SQL'
            UPDATE orders o
            SET sku = first_item.sku,
                quantity = item_counts.units
            FROM (
                SELECT DISTINCT ON (order_id) order_id, sku
                FROM order_items
                ORDER BY order_id, position
            ) AS first_item,
            (
                SELECT order_id, COUNT(*) AS units FROM order_items GROUP BY order_id
            ) AS item_counts
            WHERE first_item.order_id = o.id AND item_counts.order_id = o.id
        SQL);

        Schema::dropIfExists('order_items');

        DB::statement('ALTER TABLE orders ALTER COLUMN sku SET NOT NULL');

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('sku')->references('sku')->on('products')->restrictOnDelete();
        });
    }
};
