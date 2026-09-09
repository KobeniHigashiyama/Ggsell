<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening pass after an audit of stage 2.
 *
 * Three gaps, all of the same kind: a guarantee that was stated in a comment or
 * enforced in one caller rather than in the place nothing can go around.
 *
 * 1. The SKU a supplier claimed for a code was never stored, so a code recovered
 *    after a crash could not be re-checked against what was ordered.
 * 2. The append-only trigger on order_events is a row trigger, and TRUNCATE does
 *    not fire row triggers. History could be erased wholesale.
 * 3. ledger_entries — the other half of the money history, and the independent
 *    side of every reconciliation — had no such protection at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            // What the supplier said this code is for. Kept so provenance can be
            // re-checked by a later run, which is the only way a code recovered
            // after a crash can be trusted.
            $table->string('reported_sku', 64)->nullable()->after('code');
        });

        $this->protectFromTruncate('order_events', 'order_events_append_only');
        $this->makeAppendOnly('ledger_entries', 'ledger_entries_append_only');

        // The cascade declared on order_events was unreachable: a cascading DELETE
        // fires the same row trigger and is refused. Say what actually happens —
        // history outlives the order it describes.
        Schema::table('order_events', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['order_item_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_events
                ADD CONSTRAINT order_events_order_id_foreign
                FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE NO ACTION
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE order_events
                ADD CONSTRAINT order_events_order_item_id_foreign
                FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE NO ACTION
        SQL);
    }

    /** Adds the statement-level half of an append-only guarantee. */
    private function protectFromTruncate(string $table, string $function): void
    {
        DB::statement(<<<SQL
            CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION {$function}()
        SQL);
    }

    /** Refuses UPDATE, DELETE and TRUNCATE on a table that only ever grows. */
    private function makeAppendOnly(string $table, string $function): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION '{$table} is append-only: % is not allowed', TG_OP;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER {$table}_no_rewrite
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$function}()
        SQL);

        $this->protectFromTruncate($table, $function);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_rewrite ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS ledger_entries_append_only()');
        DB::statement('DROP TRIGGER IF EXISTS order_events_no_truncate ON order_events');

        DB::statement('ALTER TABLE order_events DROP CONSTRAINT order_events_order_id_foreign');
        DB::statement('ALTER TABLE order_events DROP CONSTRAINT order_events_order_item_id_foreign');

        Schema::table('order_events', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
        });

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropColumn('reported_sku');
        });
    }
};
