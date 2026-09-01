<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A supplier code that lost the race to create a delivery row.
 *
 * It represents paid inventory and cannot be discarded. Reconciliation must
 * expose it so it can be returned to the pool or written off manually.
 */
class OrphanedCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
