<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A supplier code that could not be delivered to the item it was issued for.
 *
 * Two incidents land here: a code that lost the race to an existing delivery,
 * and a code the supplier had already given to somebody else. Both represent
 * paid inventory and cannot be discarded, so reconciliation keeps them visible
 * until they are returned to the pool or written off.
 */
class OrphanedCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
