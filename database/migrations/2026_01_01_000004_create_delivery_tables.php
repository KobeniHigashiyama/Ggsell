<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('supplier', 16);

            // Delivery intent identifier. Timeout retries reuse the same request_id
            // so the supplier must return the same code.
            $table->string('request_id', 80)->unique();

            // Increment only after a definitive rejection; incrementing after a
            // timeout could issue a duplicate code.
            $table->unsignedSmallInteger('attempt_no');

            // Network retry number within one request_id for diagnostics.
            $table->unsignedSmallInteger('tries')->default(0);

            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('code', 64)->nullable();
            $table->string('error_reason', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique(['order_id', 'supplier', 'attempt_no']);
        });

        // Pending and unknown attempts form the reconciliation working set. Both
        // require resolution before another supplier request is safe.
        DB::statement(<<<'SQL'
            CREATE INDEX delivery_attempts_unresolved_idx
                ON delivery_attempts (started_at)
                WHERE status IN ('pending', 'unknown')
        SQL);

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Final guard against multiple deliveries for one order.
            $table->unsignedBigInteger('order_id')->unique();

            // A code cannot belong to multiple orders, protecting against supplier
            // errors outside the core's inventory boundary.
            $table->string('code', 64)->unique();

            $table->unsignedBigInteger('delivery_attempt_id');
            $table->string('supplier', 16);
            $table->timestamp('delivered_at');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('delivery_attempt_id')->references('id')->on('delivery_attempts')->restrictOnDelete();
        });

        // Paid supplier codes that lost the race to create a delivery remain
        // visible for reconciliation.
        Schema::create('orphaned_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('delivery_attempt_id');
            $table->string('supplier', 16);
            $table->string('code', 64);
            $table->string('reason', 64);
            $table->timestamp('resolved_at')->nullable();
            // Record who resolved the discrepancy and why for future incident review.
            $table->string('resolved_by', 64)->nullable();
            $table->string('resolution', 255)->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            // Keep one row per problem so retries do not duplicate reconciliation data.
            $table->unique(['order_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orphaned_codes');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('delivery_attempts');
    }
};
