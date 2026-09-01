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
}
