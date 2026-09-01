<?php

declare(strict_types=1);

namespace App\Stub\Supplier;

enum ChaosMode: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Timeout = 'timeout';
    case OutOfStock = 'out_of_stock';
}
