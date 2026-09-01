<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Public identifier used by the payment contract; internal bigint IDs
            // are not exposed.
            $table->string('public_id', 40)->unique();
            $table->string('sku', 64);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->string('customer_email')->nullable();
            $table->timestamp('paid_at')->nullable();
            // Timestamp of the last applied payment event, used to reject stale
            // webhooks that arrive out of order.
            $table->timestamp('last_payment_event_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            // Limits background recovery so a broken order cannot retry forever.
            $table->unsignedSmallInteger('fulfilment_runs')->default(0);
            $table->timestamps();

            $table->foreign('sku')->references('sku')->on('products')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_positive CHECK (amount_minor > 0)');

        // Partial index over incomplete orders, the working set for recovery and
        // reconciliation, without growing with delivered order history.
        DB::statement(<<<'SQL'
            CREATE INDEX orders_unsettled_idx
                ON orders (status, updated_at)
                WHERE status IN ('paid', 'delivering', 'out_of_stock', 'delivery_failed')
        SQL);

        DB::statement('CREATE INDEX orders_created_at_idx ON orders (created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
