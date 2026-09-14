<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single JSON envelope every /api/v1 endpoint answers in.
 *
 * Success: {"data": ..., "meta": {...}}
 * Failure: {"error": {"code": "...", "message": "...", "details": {...}}}
 *
 * The mobile app relies on this shape, so endpoints must not hand back bare
 * arrays or resources.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data, array $meta = [], int $status = Response::HTTP_OK): JsonResponse
    {
        $payload = ['data' => self::normalise($data)];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function accepted(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, Response::HTTP_ACCEPTED);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Wrap a paginator, moving the page details into `meta` and dropping
     * Laravel's default `links` block.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(LengthAwarePaginator $paginator, array $meta = []): JsonResponse
    {
        return self::ok($paginator->items(), [
            ...$meta,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }

    private static function normalise(mixed $data): mixed
    {
        /** ResourceCollection extends JsonResource, so this covers both. */
        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        return $data;
    }
}
