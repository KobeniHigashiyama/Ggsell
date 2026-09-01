<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payments\Actions\ReplayPendingEvents;
use App\Domain\Payments\Models\PaymentEvent;
use App\Jobs\FulfilOrderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        Queue::fake();
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'public_id' => Order::newPublicId(),
            'sku' => 'KEY-CS2-PRIME',
            'quantity' => 1,
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);
    }

    private function webhook(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt_test_1',
            'order_id' => 'ord_missing',
            'status' => 'paid',
            'amount' => 1290,
            'currency' => 'RUB',
            'created_at' => now()->toIso8601String(),
        ], $overrides);
    }

    #[Test]
    public function payment_transitions_order_to_paid_and_queues_delivery(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/webhooks/payment', $this->webhook(['order_id' => $order->public_id]))
            ->assertOk()
            ->assertJson(['outcome' => 'applied']);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);

        Queue::assertPushed(FulfilOrderJob::class);
        $this->assertLedgerBalanced();

        $this->assertDatabaseHas('ledger_entries', [
            'account' => 'customer_liability',
            'direction' => 'credit',
            'amount_minor' => -129000,
            'ref_id' => 'evt_test_1',
        ]);
        $this->assertDatabaseMissing('ledger_entries', ['account' => 'revenue']);
    }

    #[Test]
    public function repeated_webhook_with_same_event_id_changes_nothing(): void
    {
        $order = $this->makeOrder();
        $payload = $this->webhook(['order_id' => $order->public_id]);

        $this->postJson('/api/v1/webhooks/payment', $payload)->assertOk();
        $paidAt = $order->refresh()->paid_at;

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/webhooks/payment', $payload)
                ->assertOk()
                ->assertJson(['outcome' => 'duplicate']);
        }

        $order->refresh();

        $this->assertSame(1, PaymentEvent::query()->count(), 'Retries must not create new events.');
        $this->assertEquals($paidAt, $order->paid_at, 'A retry must not overwrite the payment time.');
        $this->assertSame(1, DB::table('ledger_entries')->where('ref_id', 'evt_test_1')->where('account', 'cash')->count());
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function webhook_received_before_order_is_applied_when_order_appears(): void
    {
        $publicId = 'ord_'.strtolower((string) Str::ulid());

        // The order does not exist yet. Return 200 because retrying at the payment
        // system cannot resolve that condition.
        $this->postJson('/api/v1/webhooks/payment', $this->webhook(['order_id' => $publicId]))
            ->assertOk()
            ->assertJson(['outcome' => 'pending_order']);

        $event = PaymentEvent::query()->sole();
        $this->assertNull($event->processed_at, 'An unapplied event must remain queued for replay.');

        $order = Order::create([
            'public_id' => $publicId,
            'sku' => 'KEY-CS2-PRIME',
            'quantity' => 1,
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        app(ReplayPendingEvents::class)->forOrder($publicId);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNotNull(PaymentEvent::query()->sole()->processed_at);
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function failed_payment_closes_order_without_ledger_entries(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'order_id' => $order->public_id,
            'status' => 'failed',
        ]))->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
        Queue::assertNotPushed(FulfilOrderJob::class);
    }

    #[Test]
    public function stale_event_does_not_revert_state(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'event_id' => 'evt_new',
            'order_id' => $order->public_id,
            'created_at' => now()->toIso8601String(),
        ]))->assertOk();

        // The failure occurred before payment but was delivered afterward.
        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'event_id' => 'evt_old',
            'order_id' => $order->public_id,
            'status' => 'failed',
            'created_at' => now()->subHour()->toIso8601String(),
        ]))->assertOk()->assertJson(['outcome' => 'stale']);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    #[Test]
    public function amount_mismatch_does_not_start_delivery(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'order_id' => $order->public_id,
            'amount' => 100,
        ]))->assertOk()->assertJson(['outcome' => 'mismatch']);

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
        Queue::assertNotPushed(FulfilOrderJob::class);
    }

    #[Test]
    public function fractional_amount_is_rounded_instead_of_truncated(): void
    {
        // 1290.35 is slightly lower as a double, so naive integer casting after
        // multiplication yields 129034. This distinguishes rounding from casting.
        $order = Order::create([
            'public_id' => Order::newPublicId(),
            'sku' => 'KEY-CS2-PRIME',
            'quantity' => 1,
            'amount_minor' => 129035,
            'currency' => 'RUB',
            'status' => OrderStatus::Created,
        ]);

        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'order_id' => $order->public_id,
            'amount' => '1290.35',
        ]))->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function malformed_amount_is_rejected_by_validation(): void
    {
        $order = $this->makeOrder();

        foreach (['1e20', '12.345', -5] as $bad) {
            $this->postJson('/api/v1/webhooks/payment', $this->webhook([
                'event_id' => 'evt_bad_'.md5((string) $bad),
                'order_id' => $order->public_id,
                'amount' => $bad,
            ]))->assertStatus(422);
        }

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
    }

    #[Test]
    public function failure_with_mismatched_amount_still_closes_order(): void
    {
        $order = $this->makeOrder();

        // Amount is irrelevant for a failure and must not block its transition.
        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'order_id' => $order->public_id,
            'status' => 'failed',
            'amount' => 0,
        ]))->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);
    }

    #[Test]
    public function unknown_currency_is_rejected_at_boundary(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/webhooks/payment', $this->webhook([
            'order_id' => $order->public_id,
            'currency' => 'USD',
        ]))->assertStatus(422)->assertJsonValidationErrors('currency');

        $this->assertSame(OrderStatus::Created, $order->refresh()->status);
        $this->assertDatabaseCount('payment_events', 0);
    }
}
