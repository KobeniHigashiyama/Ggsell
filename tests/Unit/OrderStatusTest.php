<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ordering\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    #[Test]
    public function доставленный_заказ_финален(): void
    {
        foreach (OrderStatus::cases() as $target) {
            $this->assertFalse(
                OrderStatus::Delivered->canTransitionTo($target),
                "Из delivered не должно быть перехода в {$target->value}.",
            );
        }
    }

    #[Test]
    public function восстановимые_состояния_возвращаются_в_выдачу(): void
    {
        $this->assertTrue(OrderStatus::OutOfStock->canTransitionTo(OrderStatus::Delivering));
        $this->assertTrue(OrderStatus::DeliveryFailed->canTransitionTo(OrderStatus::Delivering));
    }

    #[Test]
    public function неоплаченный_заказ_нельзя_сразу_выдать(): void
    {
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivered));
    }

    #[Test]
    public function запоздавший_успех_оплаты_принимается(): void
    {
        $this->assertTrue(OrderStatus::PaymentFailed->canTransitionTo(OrderStatus::Paid));
    }

    #[Test]
    public function состояния_с_полученными_деньгами_размечены_верно(): void
    {
        $this->assertFalse(OrderStatus::Created->moneyReceived());
        $this->assertFalse(OrderStatus::PaymentFailed->moneyReceived());

        foreach ([OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered, OrderStatus::OutOfStock, OrderStatus::DeliveryFailed] as $status) {
            $this->assertTrue($status->moneyReceived(), "{$status->value} должен считаться оплаченным.");
        }
    }
}
