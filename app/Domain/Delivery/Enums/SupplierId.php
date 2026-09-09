<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

enum SupplierId: string
{
    case A = 'a';
    case B = 'b';

    public static function chain(): array
    {
        return array_map(
            static fn (string $id): self => self::from($id),
            config('ggsell.suppliers.order'),
        );
    }
}
