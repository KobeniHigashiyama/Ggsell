<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds close the other half of a partially fulfilled order.
 *
 * A refund is an external call, so it needs the same exactly-once machinery as a
 * supplier request: a deterministic request id the gateway deduplicates on, a row
 * written before the call so a crash cannot lose it, and a status that separates
 * "definitely not refunded" from "outcome unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');

            // One refund per item is the financial mirror of one delivery per
            // item: the unique index, not the code, is what prevents paying a
            // customer back twice.
            $table->unsignedBigInteger('order_item_id')->unique();

            // Deterministic idempotency key for the gateway. Derived from the
            // item, so a retry after a timeout asks for the same refund.
            $table->string('refund_request_id', 80)->unique();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16);

            // Why the money went back: the item's last delivery failure reason.
            $table->string('reason', 64);

            $table->string('gateway_reference', 80)->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->unsignedSmallInteger('tries')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->index('order_id');
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');

        // Working set for refund recovery: everything the gateway has not
        // confirmed yet.
        DB::statement(<<<'SQL'
            CREATE INDEX refunds_unfinished_idx
                ON refunds (requested_at)
                WHERE status IN ('pending', 'unknown')
        SQL);

        Schema::table('orders', function (Blueprint $table): void {
            // The moment the order stopped owing anything in either direction.
            // delivered_at cannot express it: a fully refunded order never had a
            // delivery, and a partial one settles after its last refund.
            $table->timestamp('settled_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('settled_at');
        });

        Schema::dropIfExists('refunds');
    }
};
