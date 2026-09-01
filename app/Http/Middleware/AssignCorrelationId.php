<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Log\Correlation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assign a correlation ID on entry and return it in the response header.
 *
 * Preserve an incoming X-Correlation-Id because an upstream service may have
 * already started the trace.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        Correlation::set($request->header('X-Correlation-Id'));

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', Correlation::id());

        return $response;
    }
}
