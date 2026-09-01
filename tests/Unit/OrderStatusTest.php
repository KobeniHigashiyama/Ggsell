<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ordering\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    #[Test]
    public function delivered_order_is_final(): void
    {
        foreach (OrderStatus::cases() as $target) {
            $this->assertFalse(
                OrderStatus::Delivered->canTransitionTo($target),
                "Delivered must not transition to {$target->value}.",
            );
        }
    }

    #[Test]
    public function recoverable_states_return_to_delivery(): void
    {
        $this->assertTrue(OrderStatus::OutOfStock->canTransitionTo(OrderStatus::Delivering));
        $this->assertTrue(OrderStatus::DeliveryFailed->canTransitionTo(OrderStatus::Delivering));
    }

    #[Test]
    public function unpaid_order_cannot_be_delivered_directly(): void
    {
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivered));
    }

    #[Test]
    public function late_successful_payment_is_accepted(): void
    {
        $this->assertTrue(OrderStatus::PaymentFailed->canTransitionTo(OrderStatus::Paid));
    }

    #[Test]
    public function money_received_states_are_classified_correctly(): void
    {
        $this->assertFalse(OrderStatus::Created->moneyReceived());
        $this->assertFalse(OrderStatus::PaymentFailed->moneyReceived());

        foreach ([OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered, OrderStatus::OutOfStock, OrderStatus::DeliveryFailed] as $status) {
            $this->assertTrue($status->moneyReceived(), "{$status->value} must be considered paid.");
        }
    }
}
