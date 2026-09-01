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
    public function оплата_переводит_заказ_в_paid_и_ставит_выдачу_в_очередь(): void
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
    public function повторный_вебхук_с_тем_же_event_id_ничего_не_меняет(): void
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

        $this->assertSame(1, PaymentEvent::query()->count(), 'Повторы не должны создавать новых событий.');
        $this->assertEquals($paidAt, $order->paid_at, 'Повтор не должен переписывать момент оплаты.');
        $this->assertSame(1, DB::table('ledger_entries')->where('ref_id', 'evt_test_1')->where('account', 'cash')->count());
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function вебхук_пришедший_раньше_заказа_применяется_при_его_появлении(): void
    {
        $publicId = 'ord_'.strtolower((string) Str::ulid());

        // The order does not exist yet. Return 200 because retrying at the payment
        // system cannot resolve that condition.
        $this->postJson('/api/v1/webhooks/payment', $this->webhook(['order_id' => $publicId]))
            ->assertOk()
            ->assertJson(['outcome' => 'pending_order']);

        $event = PaymentEvent::query()->sole();
        $this->assertNull($event->processed_at, 'Непринятое событие обязано остаться в очереди на реплей.');

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
    public function неудачная_оплата_закрывает_заказ_без_денежных_проводок(): void
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
    public function протухшее_событие_не_откатывает_состояние(): void
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
    public function несовпадение_суммы_не_запускает_выдачу(): void
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
    public function сумма_с_копейками_переводится_округлением_а_не_отбрасыванием(): void
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
    public function мусорная_сумма_отвергается_валидацией(): void
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
    public function отказ_с_несовпавшей_суммой_всё_равно_закрывает_заказ(): void
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
    public function неизвестная_валюта_отвергается_на_входе(): void
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
