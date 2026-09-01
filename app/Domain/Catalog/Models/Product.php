<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $sku
 * @property int $price_minor
 */
class Product extends Model
{
    protected $primaryKey = 'sku';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'sort_rank' => 'integer',
        ];
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class, 'sku', 'sku');
    }
}
