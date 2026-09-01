<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Delivery suppliers
    |---------------------------------------------------------------------------
    | The list defines fallback order: A first, then B after a deterministic
    | rejection. A timeout is not a rejection and must not trigger fallback.
    */

    'suppliers' => [
        'order' => ['a', 'b'],

        // Stub routes live under /api. Without that prefix every request would
        // return a 404 without a reason field, be classified as Unknown, and
        // leave orders stuck instead of falling back.
        'base_url' => env('SUPPLIER_BASE_URL', 'http://web/api'),

        // The timeout must be shorter than the stub hang so the "supplier issued
        // a code but the response was lost" scenario can be reproduced.
        'timeout' => (float) env('SUPPLIER_TIMEOUT', 2),
        'connect_timeout' => (float) env('SUPPLIER_CONNECT_TIMEOUT', 1),

        // Number of retries made with the same request_id.
        'max_attempts' => (int) env('SUPPLIER_MAX_ATTEMPTS', 3),

        'backoff' => [
            'base_ms' => (int) env('SUPPLIER_BACKOFF_BASE_MS', 200),
            'max_ms' => (int) env('SUPPLIER_BACKOFF_MAX_MS', 2000),
        ],

        'breaker' => [
            'threshold' => (int) env('SUPPLIER_BREAKER_THRESHOLD', 5),
            'cooldown' => (int) env('SUPPLIER_BREAKER_COOLDOWN', 30),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Supplier stub behavior
    |---------------------------------------------------------------------------
    | Defaults provide the random failures required by the assignment. Tests do
    | not rely on probability; they force a mode through X-Chaos-Mode.
    */

    'stubs' => [
        'a' => [
            'error_rate' => (float) env('STUB_A_ERROR_RATE', 0.25),
            'timeout_rate' => (float) env('STUB_A_TIMEOUT_RATE', 0.25),
            'hang_seconds' => (float) env('STUB_A_HANG_SECONDS', 6),
        ],
        'b' => [
            'error_rate' => (float) env('STUB_B_ERROR_RATE', 0.10),
            'timeout_rate' => (float) env('STUB_B_TIMEOUT_RATE', 0.10),
            'hang_seconds' => (float) env('STUB_B_HANG_SECONDS', 6),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Recovery
    |---------------------------------------------------------------------------
    */

    'recovery' => [
        // Seconds without progress before an order is considered stuck.
        'stuck_after_seconds' => (int) env('ORDER_STUCK_AFTER_SECONDS', 60),

        // Maximum domain fulfilment runs. Further recovery is manual so a
        // hopeless order cannot consume supplier limits forever.
        'max_fulfilment_runs' => (int) env('ORDER_MAX_FULFILMENT_RUNS', 10),

        'batch_size' => (int) env('RECOVERY_BATCH_SIZE', 50),

        // Hours during which an event without an order remains eligible for
        // automatic replay. It stays visible in reconciliation afterward.
        'replay_window_hours' => (int) env('PAYMENT_REPLAY_WINDOW_HOURS', 24),
    ],

    // Supported currencies. All others are rejected at the boundary.
    'currencies' => ['RUB'],

    // Public API address used by commands that call the system over real HTTP.
    // Race reproduction must cross the network stack to test actual contention.
    'self_url' => env('SELF_BASE_URL', 'http://web/api'),

    'showcase' => [
        'page_size' => (int) env('SHOWCASE_PAGE_SIZE', 24),
        'max_page_size' => 100,
    ],
];
