<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Background recovery tasks
|--------------------------------------------------------------------------
| All tasks use withoutOverlapping. Concurrent runs would remain correct because
| unique indexes enforce exactly-once behavior, but they would duplicate work
| and make unnecessary supplier calls.
*/

// Requeue paid but undelivered orders so worker failures cannot lose an order.
Schedule::command('orders:resolve-stuck')
    ->everyMinute()
    ->withoutOverlapping();

// Give the money back for lines that can no longer be delivered, so every paid
// order reaches a terminal state without a human deciding one by one.
Schedule::command('orders:settle')
    ->everyMinute()
    ->withoutOverlapping();

// Audit what suppliers actually did, deliver or return whatever that turns up,
// and close the incident. Stage 2 requires this to need no operator.
Schedule::command('ops:auto-resolve')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('payments:replay')
    ->everyMinute()
    ->withoutOverlapping();

// Refresh the storefront stock projection. Staleness is acceptable because
// actual availability is checked during delivery.
Schedule::command('stock:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Write reconciliation output to a log so monitoring can detect discrepancies.
Schedule::command('orders:reconcile --json')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/reconciliation.log'));
