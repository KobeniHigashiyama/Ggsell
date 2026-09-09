<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Actions\FulfilOrder;
use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Enums\ViolationKind;
use App\Domain\Delivery\Models\Delivery;
use App\Domain\Delivery\Models\DeliveryAttempt;
use App\Domain\Delivery\Models\OrphanedCode;
use App\Domain\Delivery\Models\SupplierViolation;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;
use App\Domain\Ops\Reconciliation\ReconciliationReport;
use App\Domain\Ops\Recovery\AutoResolveDiscrepancies;
use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Refunds\Gateways\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakeSupplierClient;
use Tests\TestCase;

/**
 * Stage 2, task 2: the supplier cannot be trusted.
 *
 * It can quietly hand out one code twice, hand over somebody else's code, or
 * report failure after it already issued. In every case the customer must end up
 * with exactly one working code, and one code must never reach two customers.
 */
class UntrustedSupplierTest extends TestCase
{
    use RefreshDatabase;

    private FakeSupplierClient $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();

        $this->supplier = new FakeSupplierClient;
        $this->app->instance(SupplierClient::class, $this->supplier);
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
    }

    private function paidOrder(array|string $skus): Order
    {
        return $this->makeOrder($skus, [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subMinutes(30),
        ]);
    }

    /**
     * The headline requirement: one code, two customers, never.
     */
    #[Test]
    public function a_duplicated_code_never_reaches_a_second_customer(): void
    {
        $first = $this->paidOrder('KEY-CS2-PRIME');
        $second = $this->paidOrder('KEY-CS2-PRIME');

        // Supplier A hands the very same code to both orders.
        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('SHARED-CODE', 200, 6, 'KEY-CS2-PRIME'),
            SupplierResponse::ok('SHARED-CODE', 200, 6, 'KEY-CS2-PRIME'),
        ]);
        // Supplier B behaves, so the second customer can still be served.
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('HONEST-CODE', 200, 6, 'KEY-CS2-PRIME')]);

        app(FulfilOrder::class)->handle($first->id);
        app(FulfilOrder::class)->handle($second->id);

        $this->assertSame(OrderStatus::Delivered, $first->refresh()->status);
        $this->assertSame(OrderStatus::Delivered, $second->refresh()->status);

        $codes = Delivery::query()->orderBy('id')->pluck('code')->all();
        $this->assertSame(['SHARED-CODE', 'HONEST-CODE'], $codes);
        $this->assertCount(2, array_unique($codes), 'One code must never be delivered twice.');

        $violation = SupplierViolation::query()->sole();
        $this->assertSame(ViolationKind::DuplicateCode, $violation->kind);
        $this->assertSame(SupplierId::A, $violation->supplier);
        $this->assertSame('SHARED-CODE', $violation->code);

        // The refused code is paid inventory and stays visible until returned.
        $this->assertSame('duplicate_code', OrphanedCode::query()->sole()->reason);
        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function a_code_for_another_product_is_refused_and_the_line_is_served_elsewhere(): void
    {
        $order = $this->paidOrder('KEY-CS2-PRIME');

        // Supplier A grabbed the wrong box: a real code, wrong product.
        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('GTA-KEY-0001', 200, 6, 'KEY-GTA5')]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('CS2-KEY-0001', 200, 6, 'KEY-CS2-PRIME')]);

        app(FulfilOrder::class)->handle($order->id);

        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        $this->assertSame('CS2-KEY-0001', $this->deliveredCode($order), 'The customer must receive the product they ordered.');

        $violation = SupplierViolation::query()->sole();
        $this->assertSame(ViolationKind::ForeignCode, $violation->kind);
        $this->assertSame('KEY-GTA5', $violation->detail);

        // The refused attempt keeps its own truth and gains our verdict.
        $refused = DeliveryAttempt::query()->where('code', 'GTA-KEY-0001')->sole();
        $this->assertNotNull($refused->quarantined_at);
        $this->assertMoneyConserved();
    }

    /**
     * A supplier that will not say what it sold cannot be checked, so its code is
     * not delivered on faith.
     */
    #[Test]
    public function a_code_without_provenance_is_refused(): void
    {
        $order = $this->paidOrder('KEY-CS2-PRIME');

        $this->supplier->script(SupplierId::A, [SupplierResponse::ok('MYSTERY-CODE', 200, 6, '')]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('CS2-KEY-0002', 200, 6, 'KEY-CS2-PRIME')]);

        app(FulfilOrder::class)->handle($order->id);

        $this->assertSame('CS2-KEY-0002', $this->deliveredCode($order));
        $this->assertSame(ViolationKind::UnverifiedCode, SupplierViolation::query()->sole()->kind);
    }

    /**
     * Task 2, point 3: the supplier reported an error but had issued a code.
     *
     * Nobody knows until the audit asks. When the line is still waiting, the
     * right answer is to deliver that code rather than waste it.
     */
    #[Test]
    public function a_code_hidden_behind_an_error_is_found_and_delivered(): void
    {
        $order = $this->paidOrder('KEY-CS2-PRIME');
        $item = $this->itemOf($order);

        // Both suppliers report failure; A is lying about it.
        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('supplier_error', 503, 20)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('supplier_error', 503, 20)]);
        $this->supplier->alreadyIssued(
            sprintf('req_%s_a_1', $item->public_id),
            'CODE-ISSUED-BEHIND-ERROR',
            'KEY-CS2-PRIME',
        );

        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame(OrderStatus::DeliveryFailed, $order->refresh()->status);
        $this->assertDatabaseCount('deliveries', 0);

        $result = app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        $this->assertSame(1, $result['recovered']);
        $this->assertSame(OrderStatus::Delivered, $order->refresh()->status);
        $this->assertSame('CODE-ISSUED-BEHIND-ERROR', $this->deliveredCode($order));
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertMoneyConserved();
        $this->assertLedgerBalanced();
    }

    #[Test]
    public function a_code_hidden_behind_an_error_is_returned_when_the_line_was_served_elsewhere(): void
    {
        $order = $this->paidOrder('KEY-CS2-PRIME');
        $item = $this->itemOf($order);

        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('supplier_error', 503, 20)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('CODE-FROM-B', 200, 6, 'KEY-CS2-PRIME')]);
        $this->supplier->alreadyIssued(
            sprintf('req_%s_a_1', $item->public_id),
            'WASTED-CODE',
            'KEY-CS2-PRIME',
        );

        app(FulfilOrder::class)->handle($order->id);
        $this->assertSame('CODE-FROM-B', $this->deliveredCode($order));

        $result = app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);

        // The customer keeps the one working code; the stray one goes back.
        $this->assertSame(1, $result['quarantined']);
        $this->assertDatabaseCount('deliveries', 1);
        $this->assertSame(ViolationKind::IssuedAfterError, SupplierViolation::query()->sole()->kind);
        $this->assertSame(
            [['supplier' => 'a', 'code' => 'WASTED-CODE', 'reason' => 'issued_after_error']],
            $this->supplier->returnedCodes,
        );
        $this->assertMoneyConserved();
    }

    /**
     * Task 2, point 4: discrepancies are sorted out without a human.
     */
    #[Test]
    public function discrepancies_are_closed_automatically(): void
    {
        $first = $this->paidOrder('KEY-CS2-PRIME');
        $second = $this->paidOrder('KEY-CS2-PRIME');

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('ONE-CODE', 200, 6, 'KEY-CS2-PRIME'),
            SupplierResponse::ok('ONE-CODE', 200, 6, 'KEY-CS2-PRIME'),
        ]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::ok('OTHER-CODE', 200, 6, 'KEY-CS2-PRIME')]);

        app(FulfilOrder::class)->handle($first->id);
        app(FulfilOrder::class)->handle($second->id);

        $report = app(ReconciliationReport::class);
        $before = $report->build(0);
        $this->assertSame(1, $before['checks']['supplier_violations']['count']);
        $this->assertSame(1, $before['checks']['orphaned_codes']['count']);
        $this->assertFalse($report->isHealthy($before));

        // One scheduled sweep, no operator.
        $result = app(AutoResolveDiscrepancies::class)->handle(graceSeconds: 0);
        $this->assertSame(1, $result['returned']);
        $this->assertSame(1, $result['closed']);

        $after = $report->build(0);
        $this->assertSame(0, $after['checks']['supplier_violations']['count']);
        $this->assertSame(0, $after['checks']['orphaned_codes']['count']);
        $this->assertTrue($report->isHealthy($after), json_encode($after['checks'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * A refused code must not be resurrected by any recovery path: it is not this
     * line's code, no matter how many times the line is retried.
     */
    #[Test]
    public function a_quarantined_code_is_never_reused_by_recovery(): void
    {
        $first = $this->paidOrder('KEY-CS2-PRIME');
        $second = $this->paidOrder('KEY-CS2-PRIME');

        $this->supplier->script(SupplierId::A, [
            SupplierResponse::ok('ONLY-CODE', 200, 6, 'KEY-CS2-PRIME'),
            SupplierResponse::ok('ONLY-CODE', 200, 6, 'KEY-CS2-PRIME'),
        ]);
        // Nothing is available for the second line anywhere.
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);

        app(FulfilOrder::class)->handle($first->id);
        app(FulfilOrder::class)->handle($second->id);

        // A duplicate from one supplier plus an empty pool at the other leaves the
        // line recoverable, reported with the reason the last supplier gave.
        $secondItem = OrderItem::query()->where('order_id', $second->id)->sole();
        $this->assertSame(OrderItemStatus::OutOfStock, $secondItem->status);

        // Replaying the line must not pick the quarantined code back up.
        $this->supplier->script(SupplierId::A, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        $this->supplier->script(SupplierId::B, [SupplierResponse::rejected('out_of_stock', 409, 8)]);
        app(FulfilOrder::class)->handle($second->id);

        $this->assertDatabaseCount('deliveries', 1);
        $this->assertSame('ONLY-CODE', $this->deliveredCode($first));
        $this->assertNull($this->deliveredCode($second));
        $this->assertMoneyConserved();
    }
}
