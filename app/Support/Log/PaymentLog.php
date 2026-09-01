<?php

declare(strict_types=1);

namespace App\Support\Log;

use Illuminate\Support\Facades\Log;

/**
 * Write the monetary path as structured JSON in a dedicated channel.
 *
 * Payment traces require different retention and consumers from general
 * application logs.
 */
final class PaymentLog
{
    /** @param array<string, mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        Log::channel('payments')->info($event, Correlation::decorate($context));
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        Log::channel('payments')->warning($event, Correlation::decorate($context));
    }

    /** @param array<string, mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        Log::channel('payments')->error($event, Correlation::decorate($context));
    }
}
