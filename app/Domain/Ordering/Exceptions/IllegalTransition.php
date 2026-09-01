<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use DomainException;

class IllegalTransition extends DomainException
{
    public function __construct(
        string $message,
        public readonly ?string $orderPublicId = null,
        public readonly ?OrderStatus $from = null,
        public readonly ?OrderStatus $to = null,
    ) {
        parent::__construct($message);
    }

    public static function between(Order $order, OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            sprintf('Order %s cannot move from %s to %s.', $order->public_id, $from->value, $to->value),
            $order->public_id,
            $from,
            $to,
        );
    }
}
