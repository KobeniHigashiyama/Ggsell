<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Models\PaymentEvent;

final readonly class RecordedEvent
{
    public function __construct(
        public PaymentEvent $event,
        public bool $isFirstDelivery,
    ) {}
}
