<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier stubs live in a separate PostgreSQL schema.
 *
 * The key pool and request_id registry are private supplier state. A separate
 * schema enforces the boundary: the core has no models or foreign keys pointing
 * here and communicates only over HTTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Recreate the schema because migrate:fresh only clears public; otherwise
        // issued keys would leak between test runs.
        DB::statement('DROP SCHEMA IF EXISTS stub CASCADE');
        DB::statement('CREATE SCHEMA stub');

        DB::statement(<<<'SQL'
            CREATE TABLE stub.supplier_keys (
                id          bigserial PRIMARY KEY,
                supplier    varchar(16) NOT NULL,
                sku         varchar(64) NOT NULL,
                code        varchar(64) NOT NULL,
                status      varchar(16) NOT NULL DEFAULT 'available',
                request_id  varchar(80),
                claimed_at  timestamptz,
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_keys_code_unique UNIQUE (supplier, code)
            )
        SQL);

        // FOR UPDATE SKIP LOCKED uses this index so concurrent deliveries consume
        // distinct available keys without waiting in one queue.
        DB::statement(<<<'SQL'
            CREATE INDEX supplier_keys_available_idx
                ON stub.supplier_keys (supplier, sku, id)
                WHERE status = 'available'
        SQL);

        // A unique request_id makes timeout retries return the stored code rather
        // than issuing another key.
        DB::statement(<<<'SQL'
            CREATE TABLE stub.supplier_requests (
                request_id  varchar(80) PRIMARY KEY,
                supplier    varchar(16) NOT NULL,
                sku         varchar(64) NOT NULL,
                order_ref   varchar(40) NOT NULL,
                code        varchar(64) NOT NULL,
                key_id      bigint NOT NULL REFERENCES stub.supplier_keys (id),
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT supplier_requests_key_unique UNIQUE (key_id)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS stub CASCADE');
    }
};
