<?php

declare(strict_types=1);

namespace App\Stub\Supplier\Models;

use Illuminate\Database\Eloquent\Model;

/** Supplier key pool. This is private stub state and is invisible to the core. */
class SupplierKey extends Model
{
    protected $table = 'stub.supplier_keys';

    public $timestamps = false;

    protected $guarded = [];
}
