<?php

declare(strict_types=1);

namespace App\Stub\Supplier\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registry of completed supplier requests.
 *
 * The PRIMARY KEY on request_id enforces the core contract: repeating a request
 * with the same ID cannot issue a second key.
 */
class SupplierRequest extends Model
{
    protected $table = 'stub.supplier_requests';

    protected $primaryKey = 'request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
