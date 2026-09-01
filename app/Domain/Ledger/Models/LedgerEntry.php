<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger entry with signed amounts: debits are positive and credits are negative,
 * so each transaction balances when SUM(amount) equals zero.
 */
class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account' => Account::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
