<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Enums\ViolationKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded instance of a supplier breaking its contract.
 *
 * @property ViolationKind $kind
 * @property SupplierId $supplier
 */
class SupplierViolation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => ViolationKind::class,
            'supplier' => SupplierId::class,
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}
