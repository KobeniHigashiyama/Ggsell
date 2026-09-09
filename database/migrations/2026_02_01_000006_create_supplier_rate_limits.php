<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2, task 3: the supplier accepts a limited number of requests per minute.
 *
 * The core keeps its own counter and the stub keeps one too, and both count the
 * same way over the same window boundaries. That symmetry is the point: a
 * limiter that models the counterparty's accounting differently from the
 * counterparty will eventually disagree with it, and the disagreement shows up
 * as rejected traffic rather than as a warning.
 *
 * A fixed window aligned to the wall-clock minute is not a stylistic choice, it
 * is the supplier's contract. Anything smoother would waste allowance the
 * supplier is willing to serve; anything burstier is exactly the bug this shape
 * replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE supplier_rate_limits (
                supplier         varchar(16) PRIMARY KEY,
                -- Start of the window this counter belongs to, aligned the same
                -- way the supplier aligns its own.
                window_start     timestamptz NOT NULL,
                used             integer     NOT NULL DEFAULT 0,
                limit_per_window integer     NOT NULL,
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_rate_limits_used_within_limit CHECK (used <= limit_per_window),
                CONSTRAINT supplier_rate_limits_limit_positive CHECK (limit_per_window > 0)
            )
        SQL);

        // The stub's own accounting, in its own schema. It is the supplier's
        // opinion of how many requests it received, which is what makes the
        // core's restraint verifiable instead of self-reported.
        DB::statement(<<<'SQL'
            CREATE TABLE stub.rate_windows (
                supplier     varchar(16) NOT NULL,
                window_start timestamptz NOT NULL,
                used         integer     NOT NULL DEFAULT 0,
                rejected     integer     NOT NULL DEFAULT 0,
                PRIMARY KEY (supplier, window_start)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stub.rate_windows');
        Schema::dropIfExists('supplier_rate_limits');
    }
};
