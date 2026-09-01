<?php

declare(strict_types=1);

namespace App\Support\Log;

use Illuminate\Support\Str;

/**
 * End-to-end processing correlation ID.
 *
 * Middleware creates it at the HTTP boundary and carries it in the delivery job
 * payload. One value then links webhook receipt, payment application, supplier
 * attempts in another process, and fallback.
 */
final class Correlation
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= (string) Str::uuid();
    }

    public static function set(?string $id): void
    {
        self::$id = $id !== null && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function decorate(array $context): array
    {
        return ['correlation_id' => self::id()] + $context;
    }
}
