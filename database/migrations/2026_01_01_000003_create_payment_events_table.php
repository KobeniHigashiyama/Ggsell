<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // The unique index is the concurrency-safe arbiter for duplicate webhooks.
            $table->string('event_id', 64)->unique();

            // Stored without a foreign key because an event may precede its order.
            $table->string('order_public_id', 40);
            $table->unsignedBigInteger('order_id')->nullable();

            $table->string('status', 16);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->jsonb('payload');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index(['order_public_id', 'received_at']);
        });

        // Queue of events that arrived before their orders and await replay.
        DB::statement(<<<'SQL'
            CREATE INDEX payment_events_unprocessed_idx
                ON payment_events (order_public_id, received_at)
                WHERE processed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
