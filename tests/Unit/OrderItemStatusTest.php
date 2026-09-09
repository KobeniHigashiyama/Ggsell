<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ordering\Enums\OrderItemStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderItemStatusTest extends TestCase
{
    #[Test]
    public function settled_states_are_final(): void
    {
        foreach ([OrderItemStatus::Delivered, OrderItemStatus::Refunded] as $settled) {
            $this->assertTrue($settled->isSettled());

            foreach (OrderItemStatus::cases() as $target) {
                $this->assertFalse(
                    $settled->canTransitionTo($target),
                    "{$settled->value} must not transition to {$target->value}.",
                );
            }
        }
    }

    #[Test]
    public function recoverable_states_return_to_delivery(): void
    {
        $this->assertTrue(OrderItemStatus::OutOfStock->canTransitionTo(OrderItemStatus::Delivering));
        $this->assertTrue(OrderItemStatus::DeliveryFailed->canTransitionTo(OrderItemStatus::Delivering));

        // A concurrent run may commit a code after this one reported a shortage.
        $this->assertTrue(OrderItemStatus::OutOfStock->canTransitionTo(OrderItemStatus::Delivered));
        $this->assertTrue(OrderItemStatus::DeliveryFailed->canTransitionTo(OrderItemStatus::Delivered));
    }

    #[Test]
    public function recoverable_states_can_be_refunded(): void
    {
        $this->assertTrue(OrderItemStatus::OutOfStock->canTransitionTo(OrderItemStatus::Refunded));
        $this->assertTrue(OrderItemStatus::DeliveryFailed->canTransitionTo(OrderItemStatus::Refunded));
        $this->assertTrue(OrderItemStatus::Pending->canTransitionTo(OrderItemStatus::Refunded));
    }

    #[Test]
    public function a_pending_item_cannot_be_delivered_without_a_delivery_run(): void
    {
        $this->assertFalse(OrderItemStatus::Pending->canTransitionTo(OrderItemStatus::Delivered));
    }

    #[Test]
    public function in_flight_states_are_classified_correctly(): void
    {
        $this->assertTrue(OrderItemStatus::Pending->isInFlight());
        $this->assertTrue(OrderItemStatus::Delivering->isInFlight());

        $this->assertFalse(OrderItemStatus::OutOfStock->isInFlight());
        $this->assertTrue(OrderItemStatus::OutOfStock->isRecoverable());
        $this->assertTrue(OrderItemStatus::DeliveryFailed->isRecoverable());
    }
}
