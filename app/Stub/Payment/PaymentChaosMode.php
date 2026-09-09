<?php

declare(strict_types=1);

namespace App\Stub\Payment;

enum PaymentChaosMode: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Timeout = 'timeout';
}
