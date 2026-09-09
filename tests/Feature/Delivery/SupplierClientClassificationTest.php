<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\HttpSupplierClient;
use App\Domain\Delivery\Suppliers\SupplierOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How a failure to reach the supplier is classified.
 *
 * The same network error means opposite things depending on what was being
 * asked, and getting that backwards is expensive in both directions.
 */
class SupplierClientClassificationTest extends TestCase
{
    private function refuseConnections(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 7: Failed to connect to web port 80');
        });
    }

    /**
     * Asking for a code and never reaching the supplier means no code was
     * issued, so another supplier may be tried.
     */
    #[Test]
    public function an_unreachable_supplier_rejects_an_issue_request(): void
    {
        $this->refuseConnections();

        $response = app(HttpSupplierClient::class)->issue(SupplierId::A, 'req_x_a_1', 'KEY-CS2-PRIME', 'itm_x');

        $this->assertSame(SupplierOutcome::Rejected, $response->outcome);
        $this->assertSame('unreachable', $response->reason);
    }

    /**
     * Asking what the supplier did and never reaching it means nothing at all.
     *
     * Treating it as a rejection is how an audit concludes "no code exists" from
     * an answer it never received, which frees the line to buy a second key or
     * take a refund for a code the supplier already issued.
     */
    #[Test]
    public function an_unreachable_supplier_leaves_a_verification_unknown(): void
    {
        $this->refuseConnections();

        $response = app(HttpSupplierClient::class)->verify(SupplierId::A, 'req_x_a_1');

        $this->assertSame(SupplierOutcome::Unknown, $response->outcome);
        $this->assertSame('unreachable', $response->reason);
    }

    /**
     * A refusal to answer is not an answer either, even when it arrives as a
     * perfectly well-formed HTTP response.
     */
    #[Test]
    public function a_rate_limited_verification_is_unknown_rather_than_a_denial(): void
    {
        Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'rate_limited'], 429));

        $response = app(HttpSupplierClient::class)->verify(SupplierId::A, 'req_x_a_1');

        $this->assertSame(SupplierOutcome::Unknown, $response->outcome);
    }

    /**
     * A supplier declining to answer is not a supplier saying it sold nothing.
     *
     * issue() reads a contract-shaped error as a definitive rejection, and that
     * is right there: "out of stock" tells us no key was taken. The same body in
     * an answer about what already happened tells us nothing at all, and reading
     * it as "no record" lets an audit close an attempt and free the line to buy a
     * second key for one payment.
     */
    #[Test]
    public function a_server_error_during_verification_is_unknown_even_with_a_reason(): void
    {
        Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'supplier_error'], 503));

        $response = app(HttpSupplierClient::class)->verify(SupplierId::A, 'req_x_a_1');

        $this->assertSame(SupplierOutcome::Unknown, $response->outcome);
        $this->assertSame('supplier_error', $response->reason);
    }

    /**
     * The same body asking for a code remains a definitive rejection, because
     * there it really is one.
     */
    #[Test]
    public function a_server_error_when_asking_for_a_code_stays_a_rejection(): void
    {
        Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'supplier_error'], 503));

        $response = app(HttpSupplierClient::class)->issue(SupplierId::A, 'req_x_a_1', 'KEY-CS2-PRIME', 'itm_x');

        $this->assertSame(SupplierOutcome::Rejected, $response->outcome);
    }

    /**
     * The one answer that does prove nothing was issued.
     */
    #[Test]
    public function a_supplier_with_no_record_of_the_request_answers_definitively(): void
    {
        Http::fake(fn () => Http::response(['status' => 'error', 'reason' => 'unknown_request'], 404));

        $response = app(HttpSupplierClient::class)->verify(SupplierId::A, 'req_x_a_1');

        $this->assertSame(SupplierOutcome::Rejected, $response->outcome);
        $this->assertSame('unknown_request', $response->reason);
    }
}
