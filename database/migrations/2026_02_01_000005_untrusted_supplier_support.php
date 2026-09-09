<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, task 2: the supplier can no longer be trusted.
 *
 * Two things are needed. The stub gains the ability to misbehave on purpose,
 * which means relaxing its own honesty constraint for chaos rows only. The core
 * gains somewhere to record that it caught the supplier at it, plus a way to
 * mark a code it refuses to hand over.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A code the core received but judged untrustworthy. The attempt keeps
        // its own truth ("the supplier answered ok with code X"); this column is
        // our verdict on it, so the two are never confused.
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->timestamp('quarantined_at')->nullable()->after('code');

            // When the supplier's own registry was last asked about this request.
            // A reported failure is only believed until someone checks.
            $table->timestamp('verified_at')->nullable()->after('quarantined_at');
        });

        // Failed attempts nobody has audited yet: the working set for catching a
        // supplier that issued a code behind an error response.
        DB::statement(<<<'SQL'
            CREATE INDEX delivery_attempts_unaudited_idx
                ON delivery_attempts (started_at)
                WHERE status = 'failed' AND verified_at IS NULL
        SQL);

        Schema::create('supplier_violations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('supplier', 16);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedBigInteger('delivery_attempt_id')->nullable();

            // What the supplier did: duplicate_code, foreign_code, issued_after_error.
            $table->string('kind', 32);
            $table->string('code', 64)->nullable();
            $table->string('detail', 255)->nullable();

            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 255)->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();

            // One record per incident. A retry that re-detects the same bad code
            // for the same attempt must not inflate the supplier's score.
            $table->unique(['delivery_attempt_id', 'kind', 'code'], 'supplier_violations_incident_unique');
            $table->index(['supplier', 'detected_at']);
        });

        DB::statement(<<<'SQL'
            CREATE INDEX supplier_violations_open_idx
                ON supplier_violations (detected_at)
                WHERE resolved_at IS NULL
        SQL);

        $this->relaxStubHonesty();
    }

    /**
     * Lets the stub issue one key twice, but only in chaos mode.
     *
     * The unique index on key_id is what makes the honest stub honest, so it is
     * kept for honest rows and lifted only for deliberately dishonest ones.
     */
    private function relaxStubHonesty(): void
    {
        DB::statement('ALTER TABLE stub.supplier_requests ALTER COLUMN key_id DROP NOT NULL');
        DB::statement('ALTER TABLE stub.supplier_requests DROP CONSTRAINT supplier_requests_key_unique');
        DB::statement('ALTER TABLE stub.supplier_requests ADD COLUMN chaos boolean NOT NULL DEFAULT false');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX supplier_requests_key_unique
                ON stub.supplier_requests (key_id)
                WHERE NOT chaos
        SQL);

        // Codes handed back by the core after an incident. Revoked keys never
        // return to circulation, which is what makes a returned duplicate safe.
        DB::statement('ALTER TABLE stub.supplier_keys ADD COLUMN returned_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stub.supplier_keys DROP COLUMN IF EXISTS returned_at');
        DB::statement('DROP INDEX IF EXISTS stub.supplier_requests_key_unique');
        DB::statement('ALTER TABLE stub.supplier_requests DROP COLUMN IF EXISTS chaos');
        DB::statement('DELETE FROM stub.supplier_requests WHERE key_id IS NULL');
        DB::statement('ALTER TABLE stub.supplier_requests ALTER COLUMN key_id SET NOT NULL');
        DB::statement('ALTER TABLE stub.supplier_requests ADD CONSTRAINT supplier_requests_key_unique UNIQUE (key_id)');

        Schema::dropIfExists('supplier_violations');

        DB::statement('DROP INDEX IF EXISTS delivery_attempts_unaudited_idx');

        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropColumn(['quarantined_at', 'verified_at']);
        });
    }
};
