<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consistent API envelope:
 * { "success": bool, "message": string|null, "data": mixed, "meta": object|null, "error": {code, details}|null }
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $p = $data->resource;
            $meta = array_merge([
                'pagination' => [
                    'current_page' => $p->currentPage(),
                    'per_page' => $p->perPage(),
                    'total' => $p->total(),
                    'last_page' => $p->lastPage(),
                ],
            ], $meta);
            $data = $data->collection;
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        } elseif (is_iterable($data)) {
            $data = collect($data)->map(fn ($item) => $item instanceof JsonResource ? $item->resolve(request()) : $item)->all();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta ?: null,
            'error' => null,
        ], $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::ok($data, $message, 201);
    }

    public static function error(string $message, string $code = 'error', int $status = 400, array $details = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => null,
            'error' => ['code' => $code, 'details' => $details ?: null],
        ], $status);
    }
}
