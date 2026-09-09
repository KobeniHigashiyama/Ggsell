<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Suppliers;

use App\Domain\Delivery\Enums\SupplierId;

interface SupplierClient
{
    /**
     * Requests a code from a supplier.
     *
     * Repeated calls with the same $requestId must return the same code, making
     * retries after a timeout safe.
     */
    public function issue(SupplierId $supplier, string $requestId, string $sku, string $orderRef): SupplierResponse;

    /**
     * Asks what the supplier did with a request id, without issuing anything.
     *
     * Reconciliation uses this instead of issue() when it only needs the truth:
     * an unresolved attempt and a request that failed loudly can both turn out to
     * have a code behind them.
     */
    public function verify(SupplierId $supplier, string $requestId): SupplierResponse;

    /**
     * Hands a code back to the supplier.
     *
     * Used when the core holds a code it can never deliver. The supplier is
     * expected to revoke it rather than resell it.
     *
     * @return bool True when the supplier acknowledged the return.
     */
    public function returnCode(SupplierId $supplier, string $code, string $reason): bool;
}
