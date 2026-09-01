<?php

use App\Domain\Ordering\Exceptions\ProductUnavailable;
use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    // Web routes are intentionally disabled because this backend exposes only
    // the JSON API and health check.
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Assign correlation IDs first so even validation failures are traceable.
        $middleware->api(prepend: [AssignCorrelationId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A domain rejection is a valid response, not a server failure. Without
        // explicit rendering the client could not distinguish it from a 500.
        // IllegalTransition is deliberately excluded because it signals internal
        // inconsistency. Returning 4xx to the payment provider would suppress
        // retries and lose the event; a retryable 5xx is the correct response.
        $exceptions->render(fn (ProductUnavailable $e) => response()->json([
            'message' => $e->getMessage(),
        ], 422));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
