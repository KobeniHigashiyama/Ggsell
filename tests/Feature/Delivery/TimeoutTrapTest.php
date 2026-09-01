<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Acceptance criterion 4: a supplier times out after issuing a code.
 *
 * A timeout is not a rejection, and retries must reuse the same request_id.
 */
class TimeoutTrapTest extends TestCase
{
    use RefreshDatabase;

    private FakeSupplierClient $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();

        $this->supplier = new FakeSupplierClient;
        $this->app->instance(SupplierClient::class, $this->supplier);
    }

    private function paidOrder(): Order
    {
        return Order::create([
            'public_id' => Order::newPublicId(),
            'sku' => 'KEY-CS2-PRIME',
            'quantity' => 1,
            'amount_minor' => 129000,
            'currency' => 'RUB',
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function retry_after_timeout_reuses_request_id_without_second_delivery(): void
    {
        $order = $this->paidOrder();
        $requestId = "req_{$order->public_id}_a_1";

        $this->supplier
            ->script(SupplierId::A, [SupplierResponse::unknown('timeout', null, 2000)])
            ->alreadyIssued($requestId, 'REAL-CODE-0001');

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('REAL-CODE-0001', $order->delivery->code);

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('delivery_attempts', 1);

        $this->assertSame([$requestId, $requestId], $this->supplier->requestIds());

        $this->assertLedgerBalanced();
    }

    #[Test]
    public function unresolved_timeout_prevents_fallback_to_another_supplier(): void
    {
        $order = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::unknown('timeout', null, 2000),
            SupplierResponse::unknown('timeout', null, 2000),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('CODE-FROM-B', 200, 10)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertSame('unresolved_attempt', $order->failure_reason);
        $this->assertDatabaseCount('deliveries', 0);

        $this->assertSame(
            [SupplierId::A->value, SupplierId::A->value],
            array_column($this->supplier->calls, 'supplier'),
        );

        $attempt = DeliveryAttempt::query()->sole();
        $this->assertSame(AttemptStatus::Unknown, $attempt->status);
        $this->assertNull($attempt->finished_at, 'An unresolved attempt must remain visibly unfinished.');
    }

    #[Test]
    public function reconciling_unresolved_attempt_delivers_same_code_instead_of_new_one(): void
    {
        $order = $this->paidOrder();
        $requestId = "req_{$order->public_id}_a_1";

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::unknown('timeout', null, 2000),
            SupplierResponse::unknown('timeout', null, 2000),
        ]);

        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame(OrderStatus::DeliveryFailed, $order->refresh()->status);

        $this->supplier->alreadyIssued($requestId, 'ISSUED-BEFORE-TIMEOUT');

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('ISSUED-BEFORE-TIMEOUT', $order->delivery->code);
        $this->assertDatabaseCount('deliveries', 1);

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertSame(
            [$requestId, $requestId, $requestId],
            $this->supplier->requestIds(),
        );

        $this->assertLedgerBalanced();
    }

    /**
     * Regression: a retry transport failure does not close an unresolved attempt.
     *
     * Connection refusal on the first send means the request did not arrive. On a
     * retry after a timeout, it only means the outcome could not be queried and the
     * supplier may still hold an issued key, so fallback remains unsafe.
     */
    #[Test]
    public function transport_failure_during_retry_does_not_enable_fallback(): void
    {
        $order = $this->paidOrder();

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::unknown('timeout', null, 2000),
            // A null HTTP status means the network, not the supplier, produced the result.
            SupplierResponse::rejected('unreachable', null, 30),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('SECOND-KEY', 200, 10)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertSame('unresolved_attempt', $order->failure_reason);
        $this->assertDatabaseCount('deliveries', 0);

        $attempt = DeliveryAttempt::query()->sole();
        $this->assertSame(AttemptStatus::Unknown, $attempt->status);

        $this->assertSame(
            [SupplierId::A->value, SupplierId::A->value],
            array_column($this->supplier->calls, 'supplier'),
            'Supplier B must not receive any requests.',
        );
    }

    /**
     * Regression: a code was received before the process stopped without delivery.
     *
     * The successful response and delivery are separate transactions. A succeeded
     * attempt is not unresolved, so explicit recovery must prevent the next run
     * from creating a new request_id and consuming another key.
     */
    #[Test]
    public function uncommitted_delivery_is_recovered_without_contacting_supplier(): void
    {
        $order = $this->paidOrder();

        $attempt = DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => SupplierId::A->value,
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'CODE-BEFORE-CRASH',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('WRONG-SECOND-KEY', 200, 10)]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('CODE-BEFORE-CRASH', $order->delivery->code);
        $this->assertDatabaseCount('deliveries', 1);

        $this->assertSame([], $this->supplier->calls, 'The code is already stored, so no supplier call is needed.');
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertSame($attempt->id, Delivery::query()->sole()->delivery_attempt_id);

        $this->assertLedgerBalanced();
    }

    /**
     * Regression: pending attempts require the same caution as unknown attempts.
     *
     * A pending row means the call may have been sent before the process stopped.
     * Reconciliation cannot prove otherwise, so a transport failure cannot close
     * the attempt and permit fallback.
     */
    #[Test]
    public function reconciliation_transport_failure_does_not_close_pending_attempt(): void
    {
        $order = $this->paidOrder();

        DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => SupplierId::A->value,
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 0,
            'status' => AttemptStatus::Pending,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('unreachable', null, 30)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('SECOND-KEY', 200, 10)]);

        app(FulfilOrder::class)->handle($order->id);

        $this->assertSame(OrderStatus::DeliveryFailed, $order->refresh()->status);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertSame(AttemptStatus::Unknown, DeliveryAttempt::query()->sole()->status);

        $this->assertSame(
            [SupplierId::A->value],
            array_column($this->supplier->calls, 'supplier'),
            'Supplier B cannot be contacted while supplier A remains unresolved.',
        );
    }

    /**
     * Regression: a failed delivery commit cannot be ignored.
     *
     * If the code belongs to another order, commit fails. Treating that as success
     * would leave the order in delivering and make the scheduler retry forever.
     */
    #[Test]
    public function inability_to_commit_code_transitions_order_to_failure(): void
    {
        $other = $this->paidOrder();
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('SHARED-CODE', 200, 5)]);
        app(FulfilOrder::class)->handle($other->id);
        $this->assertSame(OrderStatus::Delivered, $other->refresh()->status);

        // Another order owns the code returned incorrectly by the supplier.
        $order = $this->paidOrder();
        DeliveryAttempt::create([
            'order_id' => $order->id,
            'supplier' => SupplierId::A->value,
            'request_id' => "req_{$order->public_id}_a_1",
            'attempt_no' => 1,
            'tries' => 1,
            'status' => AttemptStatus::Succeeded,
            'http_status' => 200,
            'code' => 'SHARED-CODE',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        app(FulfilOrder::class)->handle($order->id);

        $order->refresh();

        $this->assertSame(OrderStatus::DeliveryFailed, $order->status);
        $this->assertSame('code_belongs_to_another_order', $order->failure_reason);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertDatabaseCount('orphaned_codes', 1);

        app(FulfilOrder::class)->handle($order->id);
        $this->assertDatabaseCount('orphaned_codes', 1);
    }
}
