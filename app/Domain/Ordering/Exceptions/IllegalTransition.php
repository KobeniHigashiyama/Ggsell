<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use DomainException;

class IllegalTransition extends DomainException
{
    public function __construct(
        string $message,
        public readonly ?string $publicId = null,
        public readonly OrderStatus|OrderItemStatus|null $from = null,
        public readonly OrderStatus|OrderItemStatus|null $to = null,
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

    public static function betweenItemStates(OrderItem $item, OrderItemStatus $from, OrderItemStatus $to): self
    {
        return new self(
            sprintf('Order item %s cannot move from %s to %s.', $item->public_id, $from->value, $to->value),
            $item->public_id,
            $from,
            $to,
        );
    }
}
