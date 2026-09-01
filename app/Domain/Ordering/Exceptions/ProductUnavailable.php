<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use DomainException;

class ProductUnavailable extends DomainException
{
    public static function sku(string $sku): self
    {
        return new self(sprintf('Product %s is not available for sale.', $sku));
    }
}
