<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Groups entries for one business operation; the group sum is zero.
            $table->uuid('transaction_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('account', 32);
            $table->string('direction', 6);

            // Signed amount: debit is positive and credit is negative, allowing
            // balance verification with a simple SUM().
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // Combined with account, the source reference is an idempotency key
            // that prevents recovery from posting the same money twice.
            $table->string('ref_type', 32);
            $table->string('ref_id', 80);

            $table->timestamp('created_at');

            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->unique(['ref_type', 'ref_id', 'account']);
            $table->index('transaction_id');
            $table->index(['account', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_direction_matches_sign CHECK (
                (direction = 'debit'  AND amount_minor > 0) OR
                (direction = 'credit' AND amount_minor < 0)
            )
        SQL);

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key', 200)->unique();
            $table->string('endpoint', 64);
            // Reusing an idempotency key with another request body is a conflict,
            // not a replay of the original request.
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('ledger_entries');
    }
};
