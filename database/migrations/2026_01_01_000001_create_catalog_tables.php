<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->string('sku', 64)->primary();
            $table->string('name');
            $table->string('type', 32);
            // Money is stored as integer minor units. Webhook parsing immediately
            // converts decimal input, and the rest of the system uses integers.
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);

            // Availability is denormalized as a flag rather than a count. The flag
            // changes only when stock crosses zero, keeping frequent counter writes
            // out of the storefront covering index and preserving HOT updates.
            $table->boolean('in_stock')->default(false);
            // Deterministic storefront order provides a stable keyset cursor.
            $table->integer('sort_rank')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_positive CHECK (price_minor > 0)');

        // Partial covering index for index-only storefront scans (stage 5).
        DB::statement(<<<'SQL'
            CREATE INDEX products_showcase_idx
                ON products (type, sort_rank, sku)
                INCLUDE (name, price_minor, currency, image)
                WHERE is_active
        SQL);

        // Equivalent index for the storefront across all categories.
        DB::statement(<<<'SQL'
            CREATE INDEX products_showcase_all_idx
                ON products (sort_rank, sku)
                INCLUDE (name, type, price_minor, currency, image)
                WHERE is_active
        SQL);

        // Matching indexes for in-stock views. Without them, available_count > 0
        // is applied after the join and PostgreSQL may scan the full product index
        // to fill a page when most inventory is sold out.
        DB::statement(<<<'SQL'
            CREATE INDEX products_in_stock_idx
                ON products (type, sort_rank, sku)
                INCLUDE (name, price_minor, currency, image)
                WHERE is_active AND in_stock
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX products_in_stock_all_idx
                ON products (sort_rank, sku)
                INCLUDE (name, type, price_minor, currency, image)
                WHERE is_active AND in_stock
        SQL);

        // Supplier stock projection. Keeping the frequently updated counter in
        // a narrow table avoids storefront index writes and preserves HOT updates.
        Schema::create('product_stock', function (Blueprint $table): void {
            $table->string('sku', 64)->primary();
            $table->integer('available_count')->default(0);
            $table->integer('issued_count')->default(0);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->foreign('sku')->references('sku')->on('products')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE product_stock ADD CONSTRAINT product_stock_non_negative CHECK (available_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock');
        Schema::dropIfExists('products');
    }
};
