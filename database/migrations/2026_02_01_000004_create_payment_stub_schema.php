<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payment gateway stub.
 *
 * Like the supplier stub, it lives in its own PostgreSQL schema and is reachable
 * only over HTTP. The core owns no model and no foreign key pointing here.
 *
 * Its contract is a mirror of the supplier's: a repeated refund_request_id
 * returns the original result instead of moving money twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE stub.refund_requests (
                request_id    varchar(80) PRIMARY KEY,
                order_ref     varchar(40) NOT NULL,
                item_ref      varchar(40) NOT NULL,
                amount_minor  bigint      NOT NULL,
                currency      char(3)     NOT NULL,
                reference     varchar(80) NOT NULL,
                created_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS stub.refund_requests');
    }
};
