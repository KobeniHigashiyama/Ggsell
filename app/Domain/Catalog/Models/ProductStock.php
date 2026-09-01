<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplier stock projection.
 *
 * The core does not own the key pool; suppliers do. This table is not a source
 * of truth, but a cached answer to whether a product should be shown. A mismatch
 * with the supplier's actual stock is expected and handled as out_of_stock.
 */
class ProductStock extends Model
{
    protected $table = 'product_stock';

    protected $primaryKey = 'sku';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'available_count' => 'integer',
            'issued_count' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }
}
