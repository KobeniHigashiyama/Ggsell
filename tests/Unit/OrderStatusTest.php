<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    #[Test]
    public function payment_transitions_are_the_only_ones_left(): void
    {
        $this->assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::Paid));
        $this->assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::PaymentFailed));

        // Delivery-side statuses are derived from items, never transitioned into.
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivering));
        $this->assertFalse(OrderStatus::Created->canTransitionTo(OrderStatus::Delivered));
        $this->assertFalse(OrderStatus::OutOfStock->canTransitionTo(OrderStatus::Delivering));
    }

    #[Test]
    public function late_successful_payment_is_accepted(): void
    {
        $this->assertTrue(OrderStatus::PaymentFailed->canTransitionTo(OrderStatus::Paid));
    }

    #[Test]
    public function paid_order_ignores_further_payment_transitions(): void
    {
        foreach (OrderStatus::cases() as $target) {
            $this->assertFalse(
                OrderStatus::Paid->canTransitionTo($target),
                "A paid order must not transition to {$target->value} from the payment side.",
            );
        }
    }

    #[Test]
    public function money_received_states_are_classified_correctly(): void
    {
        $this->assertFalse(OrderStatus::Created->moneyReceived());
        $this->assertFalse(OrderStatus::PaymentFailed->moneyReceived());

        $paid = [
            OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered,
            OrderStatus::PartiallyDelivered, OrderStatus::Refunded,
            OrderStatus::OutOfStock, OrderStatus::DeliveryFailed,
        ];

        foreach ($paid as $status) {
            $this->assertTrue($status->moneyReceived(), "{$status->value} must be considered paid.");
        }
    }

    #[Test]
    public function terminal_states_are_the_settled_ones(): void
    {
        $this->assertTrue(OrderStatus::Delivered->isTerminal());
        $this->assertTrue(OrderStatus::PartiallyDelivered->isTerminal());
        $this->assertTrue(OrderStatus::Refunded->isTerminal());

        // A recoverable order still owes the customer either a code or a refund.
        $this->assertFalse(OrderStatus::OutOfStock->isTerminal());
        $this->assertFalse(OrderStatus::DeliveryFailed->isTerminal());
    }

    /**
     * The derivation matrix, including the single-item rows that must reproduce
     * stage-1 behavior exactly.
     *
     * @param  list<OrderItemStatus>  $items
     */
    #[Test]
    #[DataProvider('derivationCases')]
    public function order_status_is_derived_from_its_items(array $items, OrderStatus $expected): void
    {
        $this->assertSame($expected, OrderStatus::deriveFrom($items));
    }

    /** @return iterable<string, array{list<OrderItemStatus>, OrderStatus}> */
    public static function derivationCases(): iterable
    {
        $pending = OrderItemStatus::Pending;
        $delivering = OrderItemStatus::Delivering;
        $delivered = OrderItemStatus::Delivered;
        $refunded = OrderItemStatus::Refunded;
        $outOfStock = OrderItemStatus::OutOfStock;
        $failed = OrderItemStatus::DeliveryFailed;

        // Single-item orders keep the stage-1 meaning of every status.
        yield 'one pending line is a paid order' => [[$pending], OrderStatus::Paid];
        yield 'one delivering line' => [[$delivering], OrderStatus::Delivering];
        yield 'one delivered line' => [[$delivered], OrderStatus::Delivered];
        yield 'one out of stock line' => [[$outOfStock], OrderStatus::OutOfStock];
        yield 'one failed line' => [[$failed], OrderStatus::DeliveryFailed];
        yield 'one refunded line' => [[$refunded], OrderStatus::Refunded];

        // Multi-item orders.
        yield 'all delivered' => [[$delivered, $delivered], OrderStatus::Delivered];
        yield 'delivered and refunded is partial' => [[$delivered, $refunded], OrderStatus::PartiallyDelivered];
        yield 'everything refunded' => [[$refunded, $refunded], OrderStatus::Refunded];

        // Work in flight outranks a failure that is still being retried.
        yield 'delivering beats a failed sibling' => [[$failed, $delivering], OrderStatus::Delivering];
        yield 'pending beats a failed sibling' => [[$failed, $pending], OrderStatus::Paid];
        yield 'delivering beats pending' => [[$pending, $delivering], OrderStatus::Delivering];
        yield 'delivered plus pending is still in flight' => [[$delivered, $pending], OrderStatus::Paid];

        // Recoverable lines report the reason that covers all of them.
        yield 'only shortages' => [[$outOfStock, $outOfStock], OrderStatus::OutOfStock];
        yield 'shortage plus failure is a failure' => [[$outOfStock, $failed], OrderStatus::DeliveryFailed];
        yield 'delivered plus shortage' => [[$delivered, $outOfStock], OrderStatus::OutOfStock];
        yield 'refunded plus shortage' => [[$refunded, $outOfStock], OrderStatus::OutOfStock];
    }

    #[Test]
    public function an_order_without_items_cannot_be_derived(): void
    {
        $this->expectException(DomainException::class);

        OrderStatus::deriveFrom([]);
    }
}
