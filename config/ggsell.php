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

        // Refuse a code the supplier will not attribute to a product. A supplier
        // that cannot say what it sold cannot be checked, and stage 2 assumes it
        // should not be believed either.
        'require_code_provenance' => (bool) env('SUPPLIER_REQUIRE_CODE_PROVENANCE', true),

        'backoff' => [
            'base_ms' => (int) env('SUPPLIER_BACKOFF_BASE_MS', 200),
            'max_ms' => (int) env('SUPPLIER_BACKOFF_MAX_MS', 2000),
        ],

        'breaker' => [
            'threshold' => (int) env('SUPPLIER_BREAKER_THRESHOLD', 5),
            'cooldown' => (int) env('SUPPLIER_BREAKER_COOLDOWN', 30),
        ],

        /*
        | How many requests per minute each supplier accepts. The core never
        | exceeds it; the stub enforces the same number and answers 429 if the
        | core ever gets it wrong, so the guarantee is checkable rather than
        | asserted. Bucket capacity equals the per-minute limit, so a spike is
        | absorbed at full rate once and then paced.
        */
        'rate_limit' => [
            'a' => (int) env('SUPPLIER_A_RATE_PER_MINUTE', 30),
            'b' => (int) env('SUPPLIER_B_RATE_PER_MINUTE', 30),
        ],

        // How long a rate-limited delivery waits before trying again. Short
        // enough to keep the queue moving, long enough not to spin.
        'rate_limit_retry_seconds' => (int) env('SUPPLIER_RATE_LIMIT_RETRY_SECONDS', 5),

        // Share of each window held back for deliveries. Audits and code returns
        // use the same supplier allowance, so without a reserve a large incident
        // could spend the whole window on questions while customers wait.
        'delivery_reserve_share' => (float) env('SUPPLIER_DELIVERY_RESERVE_SHARE', 0.5),
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
    | Payment gateway
    |---------------------------------------------------------------------------
    | Refunds leave the system the same way supplier requests do, so they get the
    | same timeout discipline: the client timeout must stay below the stub hang
    | so the "money moved but the answer was lost" case is reproducible.
    */

    'payments' => [
        'base_url' => env('PAYMENT_BASE_URL', 'http://web/api'),
        'timeout' => (float) env('PAYMENT_TIMEOUT', 2),
        'connect_timeout' => (float) env('PAYMENT_CONNECT_TIMEOUT', 1),

        // Refund attempts made under one refund_request_id before the outcome is
        // left to background recovery.
        'max_attempts' => (int) env('PAYMENT_MAX_ATTEMPTS', 3),

        'stub' => [
            'error_rate' => (float) env('PAYMENT_STUB_ERROR_RATE', 0),
            'timeout_rate' => (float) env('PAYMENT_STUB_TIMEOUT_RATE', 0),
            'hang_seconds' => (float) env('PAYMENT_STUB_HANG_SECONDS', 6),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Settlement
    |---------------------------------------------------------------------------
    | When delivery is hopeless the money goes back, so that every order reaches
    | a terminal state without a human deciding one by one.
    */

    'settlement' => [
        // How long a paid line may stay undelivered before it is refunded, even
        // if its run budget is not exhausted yet.
        'give_up_after_seconds' => (int) env('SETTLEMENT_GIVE_UP_AFTER_SECONDS', 900),

        'batch_size' => (int) env('SETTLEMENT_BATCH_SIZE', 50),
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
