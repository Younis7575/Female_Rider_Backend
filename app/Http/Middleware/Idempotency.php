<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency for critical actions (booking, accepting offers, payments, refunds, payouts).
 * Clients send an "Idempotency-Key" header; replays return the original response.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        if (! $key || ! $user || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }
        if (strlen($key) > 80) {
            return ApiResponse::error('Idempotency-Key is too long.', 'validation', 422);
        }

        $route = substr($request->method().' '.$request->path(), 0, 120);
        $hash = hash('sha256', json_encode($request->except(['photo', 'file', 'document_front', 'document_back', 'selfie'])));

        $existing = IdempotencyKey::query()->where(['user_id' => $user->id, 'key' => $key, 'route' => $route])->first();
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return ApiResponse::error('Idempotency-Key was reused with a different request.', 'idempotency_conflict', 422);
            }
            if ($existing->response_code === null) {
                return ApiResponse::error('A request with this Idempotency-Key is still being processed.', 'idempotency_in_progress', 409);
            }

            return response($existing->response_body, $existing->response_code)
                ->header('Content-Type', 'application/json')->header('Idempotent-Replayed', 'true');
        }

        try {
            $record = IdempotencyKey::query()->create(['user_id' => $user->id, 'key' => $key, 'route' => $route, 'request_hash' => $hash]);
        } catch (QueryException) {
            return ApiResponse::error('A request with this Idempotency-Key is still being processed.', 'idempotency_in_progress', 409);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $record->update(['response_code' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        } else {
            $record->delete();
        }

        return $response;
    }
}
