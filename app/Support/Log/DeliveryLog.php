<?php

declare(strict_types=1);

namespace App\Support\Log;

use Illuminate\Support\Facades\Log;

/**
 * Delivery trace covering supplier calls, timeouts, fallback, and reconciliation.
 *
 * Each entry carries order_id, request_id, supplier, and attempt_no so the full
 * attempt history, including unanswered calls, can be reconstructed.
 */
final class DeliveryLog
{
    /** @param array<string, mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        Log::channel('delivery')->info($event, Correlation::decorate($context));
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        Log::channel('delivery')->warning($event, Correlation::decorate($context));
    }

    /** @param array<string, mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        Log::channel('delivery')->error($event, Correlation::decorate($context));
    }
}
