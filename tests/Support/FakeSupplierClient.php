<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Enums\SupplierId;
use App\Domain\Delivery\Suppliers\SupplierClient;
use App\Domain\Delivery\Suppliers\SupplierResponse;

/**
 * Scripted supplier client.
 *
 * Used to test orchestrator behavior such as timeouts, forbidden fallback, and
 * supplier order with deterministic responses.
 *
 * Calls are recorded so tests can assert their count and request IDs as well as
 * the final outcome.
 */
final class FakeSupplierClient implements SupplierClient
{
    /** @var list<array{supplier: string, request_id: string, sku: string, order: string}> */
    public array $calls = [];

    /** @var array<string, list<SupplierResponse>> Responses by supplier. */
    private array $script = [];

    /** @var array<string, string> Issued codes by request_id. */
    private array $issued = [];

    /** @var array<string, true> request_ids that have already been called. */
    private array $seen = [];

    /** @var array<string, string> SKU reported for each issued code. */
    private array $issuedSku = [];

    /** @var list<array{supplier: string, request_id: string}> */
    public array $verifyCalls = [];

    /** @var list<array{supplier: string, code: string, reason: string}> */
    public array $returnedCodes = [];

    /** @var array<string, array{prefix: string, timeouts: int}> Suppliers that issue and then lose the response. */
    private array $timesOutAfterIssuing = [];

    /** @var array<string, int> Timeouts served so far, by request_id. */
    private array $timeoutsServed = [];

    /** @var array<string, true> Suppliers that cannot be reached at all. */
    private array $unreachable = [];

    /**
     * Defines the supplier response sequence.
     *
     * @param  list<SupplierResponse>  $responses
     */
    public function script(SupplierId $supplier, array $responses): self
    {
        $this->script[$supplier->value] = $responses;

        return $this;
    }

    /**
     * Marks a request_id as issued by the supplier.
     *
     * The first call still follows the scripted response, usually a timeout. The
     * code is returned from the second call onward, modeling a supplier that issued
     * a key but whose response was lost.
     */
    public function alreadyIssued(string $requestId, string $code, ?string $sku = null): self
    {
        $this->issued[$requestId] = $code;

        if ($sku !== null) {
            $this->issuedSku[$requestId] = $sku;
        }

        return $this;
    }

    /**
     * Models the timeout trap for any number of requests at once.
     *
     * The scripted queue is fine for one or two responses, but a scenario about
     * mass delivery needs a supplier that behaves the same way for every request
     * it receives: claim a key, then lose the response. The code is remembered
     * per request_id, so asking again returns it rather than issuing another.
     */
    public function timesOutAfterIssuing(SupplierId $supplier, int $timeouts = 1, string $codePrefix = 'HIDDEN'): self
    {
        $this->timesOutAfterIssuing[$supplier->value] = ['prefix' => $codePrefix, 'timeouts' => $timeouts];

        return $this;
    }

    public function issue(SupplierId $supplier, string $requestId, string $sku, string $orderRef): SupplierResponse
    {
        $this->calls[] = [
            'supplier' => $supplier->value,
            'request_id' => $requestId,
            'sku' => $sku,
            'order' => $orderRef,
        ];

        $alreadyAsked = isset($this->seen[$requestId]);
        $this->seen[$requestId] = true;

        if (isset($this->timesOutAfterIssuing[$supplier->value])) {
            $behavior = $this->timesOutAfterIssuing[$supplier->value];

            // The key is claimed before the first response is lost, exactly as the
            // stub does: every later call must find this code, not buy another.
            $this->issued[$requestId] ??= $behavior['prefix'].'-'.substr(md5($requestId), 0, 10);
            $this->issuedSku[$requestId] ??= $sku;

            $served = $this->timeoutsServed[$requestId] ?? 0;

            if ($served < $behavior['timeouts']) {
                $this->timeoutsServed[$requestId] = $served + 1;

                return SupplierResponse::unknown('timeout', null, 2000);
            }

            return SupplierResponse::ok($this->issued[$requestId], 200, 5, $this->issuedSku[$requestId]);
        }

        if ($alreadyAsked && isset($this->issued[$requestId])) {
            return SupplierResponse::ok(
                $this->issued[$requestId],
                200,
                5,
                $this->issuedSku[$requestId] ?? $sku,
            );
        }

        $queue = $this->script[$supplier->value] ?? [];
        $next = array_shift($queue);
        $this->script[$supplier->value] = $queue;

        if ($next === null) {
            return SupplierResponse::rejected('no_script', 503, 5);
        }

        if ($next->isOk() && $next->code !== null) {
            $this->issued[$requestId] = $next->code;
            $this->issuedSku[$requestId] = $next->sku ?? $sku;

            // A scripted response that does not name a SKU is treated as the one
            // that was ordered, so existing scenarios stay about delivery rather
            // than provenance.
            if ($next->sku === null) {
                return SupplierResponse::ok($next->code, $next->httpStatus ?? 200, $next->latencyMs, $sku);
            }
        }

        return $next;
    }

    /**
     * Answers what the supplier did with a request id.
     *
     * A request the fake has never seen is proof that nothing was issued, which
     * is exactly what the real stub's 404 means.
     */
    /**
     * Makes a supplier unreachable, so verify cannot answer at all.
     *
     * Distinct from "the supplier says it has no record": one is evidence, the
     * other is the absence of it, and confusing them is how an audit closes an
     * attempt nobody ever spoke about.
     */
    public function unreachable(SupplierId $supplier): self
    {
        $this->unreachable[$supplier->value] = true;

        return $this;
    }

    public function verify(SupplierId $supplier, string $requestId): SupplierResponse
    {
        $this->verifyCalls[] = ['supplier' => $supplier->value, 'request_id' => $requestId];

        if (isset($this->unreachable[$supplier->value])) {
            return SupplierResponse::unknown('unreachable', null, 0);
        }

        if (isset($this->issued[$requestId])) {
            return SupplierResponse::ok($this->issued[$requestId], 200, 3, $this->issuedSku[$requestId] ?? null);
        }

        return SupplierResponse::rejected('unknown_request', 404, 3);
    }

    public function returnCode(SupplierId $supplier, string $code, string $reason): bool
    {
        $this->returnedCodes[] = ['supplier' => $supplier->value, 'code' => $code, 'reason' => $reason];

        return true;
    }

    /** @return list<string> request_ids from every supplier call. */
    public function requestIds(): array
    {
        return array_column($this->calls, 'request_id');
    }
}
