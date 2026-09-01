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
    public function alreadyIssued(string $requestId, string $code): self
    {
        $this->issued[$requestId] = $code;

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

        if ($alreadyAsked && isset($this->issued[$requestId])) {
            return SupplierResponse::ok($this->issued[$requestId], 200, 5);
        }

        $queue = $this->script[$supplier->value] ?? [];
        $next = array_shift($queue);
        $this->script[$supplier->value] = $queue;

        if ($next === null) {
            return SupplierResponse::rejected('no_script', 503, 5);
        }

        if ($next->isOk() && $next->code !== null) {
            $this->issued[$requestId] = $next->code;
        }

        return $next;
    }

    /** @return list<string> request_ids from every supplier call. */
    public function requestIds(): array
    {
        return array_column($this->calls, 'request_id');
    }
}
