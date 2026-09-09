<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-anchors delivery from the order to the order item.
 *
 * The stage-1 invariant "one delivery per order" becomes "one delivery per item".
 * UNIQUE(deliveries.code) is untouched and stays global: a code may belong to
 * exactly one item in the whole system, which is what stops a duplicated supplier
 * code from reaching two customers.
 *
 * order_id is kept on every table as a denormalized column. It carries no
 * uniqueness any more, only the ability to answer order-level questions without
 * an extra join.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->anchor('delivery_attempts');
        $this->anchor('deliveries');
        $this->anchor('orphaned_codes');

        // Attempt numbering is per item and supplier. Two items of one order may
        // legitimately hold attempt_no = 1 against the same supplier.
        DB::statement('ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_order_id_supplier_attempt_no_unique');
        DB::statement('ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_item_supplier_attempt_unique UNIQUE (order_item_id, supplier, attempt_no)');
        DB::statement('CREATE INDEX delivery_attempts_order_idx ON delivery_attempts (order_id)');

        // The final guard against a second delivery now protects the item.
        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_order_id_unique');
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_order_item_id_unique UNIQUE (order_item_id)');
        DB::statement('CREATE INDEX deliveries_order_idx ON deliveries (order_id)');

        // One report row per problematic code and item, so retries do not
        // duplicate reconciliation data.
        DB::statement('ALTER TABLE orphaned_codes DROP CONSTRAINT orphaned_codes_order_id_code_unique');
        DB::statement('ALTER TABLE orphaned_codes ADD CONSTRAINT orphaned_codes_item_code_unique UNIQUE (order_item_id, code)');

        // The run budget lives on the item now; see the order_items migration.
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('fulfilment_runs');
        });
    }

    /**
     * Adds order_item_id to a delivery-side table and backfills it.
     *
     * Stage-1 rows always belong to a single-item order, so the item is
     * unambiguous: the first line of the order the row already points at.
     */
    private function anchor(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('order_item_id')->nullable()->after('order_id');
        });

        DB::statement(<<<SQL
            UPDATE {$table} t
            SET order_item_id = (
                SELECT oi.id FROM order_items oi
                WHERE oi.order_id = t.order_id
                ORDER BY oi.position
                LIMIT 1
            )
        SQL);

        DB::statement("ALTER TABLE {$table} ALTER COLUMN order_item_id SET NOT NULL");

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('fulfilment_runs')->default(0);
        });

        DB::statement('DROP INDEX IF EXISTS deliveries_order_idx');
        DB::statement('DROP INDEX IF EXISTS delivery_attempts_order_idx');

        DB::statement('ALTER TABLE orphaned_codes DROP CONSTRAINT orphaned_codes_item_code_unique');
        DB::statement('ALTER TABLE orphaned_codes ADD CONSTRAINT orphaned_codes_order_id_code_unique UNIQUE (order_id, code)');

        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT deliveries_order_item_id_unique');
        DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_order_id_unique UNIQUE (order_id)');

        DB::statement('ALTER TABLE delivery_attempts DROP CONSTRAINT delivery_attempts_item_supplier_attempt_unique');
        DB::statement('ALTER TABLE delivery_attempts ADD CONSTRAINT delivery_attempts_order_id_supplier_attempt_no_unique UNIQUE (order_id, supplier, attempt_no)');

        foreach (['orphaned_codes', 'deliveries', 'delivery_attempts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['order_item_id']);
                $blueprint->dropColumn('order_item_id');
            });
        }
    }
};
