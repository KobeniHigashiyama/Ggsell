<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is always stored as integer minor units. Centralizing display formatting
 * prevents floats from leaking into the monetary path.
 */
final readonly class Money
{
    public static function format(int $minor, string $currency): string
    {
        return sprintf('%s.%02d %s', intdiv(abs($minor), 100) * ($minor < 0 ? -1 : 1), abs($minor) % 100, $currency);
    }

    public static function fromMajor(int|float|string $major): int
    {
        return (int) round(((float) $major) * 100);
    }
}
