<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, task 4: reconstruct any order and its money at any past moment.
 *
 * The log is written inside the same transactions that change state, so an event
 * exists if and only if the change it describes was committed. History is never
 * rewritten: a database trigger refuses UPDATE and DELETE, which makes
 * "append only" a property of the schema rather than a promise about the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id')->nullable();

            $table->string('type', 48);
            $table->jsonb('payload');

            // When the change happened, which is not always when it was recorded:
            // a replayed webhook carries its original timestamp.
            $table->timestamp('occurred_at');

            // The same idempotency key shape the ledger uses. Recovery replays a
            // step; it must not replay the history of that step.
            $table->string('ref_type', 32);
            $table->string('ref_id', 80);

            $table->timestamp('created_at');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->unique(['ref_type', 'ref_id', 'type']);
            $table->index(['order_id', 'id']);
        });

        // Point-in-time queries scan by time across all orders, period reports
        // scan by time and type.
        DB::statement('CREATE INDEX order_events_occurred_idx ON order_events (occurred_at, id)');
        DB::statement('CREATE INDEX order_events_type_occurred_idx ON order_events (type, occurred_at)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'order_events is append-only: % is not allowed', TG_OP;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER order_events_no_rewrite
                BEFORE UPDATE OR DELETE ON order_events
                FOR EACH ROW EXECUTE FUNCTION order_events_append_only()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS order_events_no_rewrite ON order_events');
        DB::statement('DROP FUNCTION IF EXISTS order_events_append_only()');
        Schema::dropIfExists('order_events');
    }
};
