<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make order creation idempotent through the Idempotency-Key header.
 *
 * A client that loses the response must be able to retry safely. Otherwise the
 * retry creates a second order and may charge the customer twice.
 *
 * Reusing a key with a different body is a client error, not a replay. Return
 * 409 instead of exposing the result of a different request.
 */
class EnsureIdempotentRequest
{
    /** Time allowed for the original request before its key is considered stuck. */
    private const IN_FLIGHT_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        $hash = hash('sha256', $request->getContent());
        $endpoint = $request->method().' '.$request->path();

        try {
            // Use a nested transaction for its SAVEPOINT. A uniqueness violation
            // aborts the surrounding Postgres transaction; the savepoint lets a
            // replay recover and return the stored response.
            DB::transaction(fn () => DB::table('idempotency_keys')->insert([
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->replay($key, $hash);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            DB::table('idempotency_keys')->where('key', $key)->update([
                'response_code' => $response->getStatusCode(),
                'response_body' => $response instanceof JsonResponse ? $response->getContent() : null,
                'updated_at' => now(),
            ]);
        } elseif ($response->getStatusCode() < 500) {
            // Client errors do not consume the key, so the corrected request may
            // reuse it. A 5xx keeps the key because the order may already have
            // committed before a later step failed; releasing it could create a
            // duplicate order for the same purchase.
            DB::table('idempotency_keys')->where('key', $key)->delete();
        }

        return $response;
    }

    private function replay(string $key, string $hash): JsonResponse
    {
        $record = DB::table('idempotency_keys')->where('key', $key)->first();

        if ($record === null) {
            return response()->json(['message' => 'Idempotency conflict.'], 409);
        }

        if ($record->request_hash !== $hash) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used with a different request body.',
            ], 409);
        }

        if ($record->response_code === null) {
            // The original request has not stored its outcome yet. It is usually
            // still running, so 409 is safer than returning an empty response.
            // A crashed process may never finish, however, and a server error
            // intentionally keeps the key because the order may have committed.
            // Expire stale keys after the wait window so legitimate retries are
            // not blocked forever. The duplicate risk is bounded and observable
            // through reconciliation, unlike a permanently blocked purchase.
            if (now()->diffInSeconds($record->created_at, absolute: true) < self::IN_FLIGHT_SECONDS) {
                return response()->json(['message' => 'Original request is still in flight.'], 409);
            }

            DB::table('idempotency_keys')->where('key', $key)->delete();

            return response()->json([
                'message' => 'Previous attempt with this Idempotency-Key did not complete. Retry the request.',
            ], 409, ['Idempotency-Reset' => 'true']);
        }

        return response()->json(
            json_decode((string) $record->response_body, true),
            $record->response_code,
            ['Idempotent-Replay' => 'true'],
        );
    }
}
