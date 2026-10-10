<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiResponse
{
    /**
     * Standard success JSON response envelope.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = [],
        array $meta = [],
        ?string $message = null,
        int $status = Response::HTTP_OK,
        array $headers = []
    ): JsonResponse {
        return new JsonResponse([
            'data' => $data,
            'meta' => (object) $meta,
            'message' => $message,
        ], $status, $headers);
    }

    /**
     * Standard paginated success JSON response envelope.
     *
     * @param  array<string, string>  $headers
     */
    public static function paginated(
        mixed $paginator,
        mixed $data = null,
        ?string $message = null,
        int $status = Response::HTTP_OK,
        array $headers = []
    ): JsonResponse {
        $meta = [
            'current_page' => method_exists($paginator, 'currentPage') ? $paginator->currentPage() : 1,
            'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : 1,
            'per_page' => method_exists($paginator, 'perPage') ? $paginator->perPage() : 20,
            'total' => method_exists($paginator, 'total') ? $paginator->total() : 0,
        ];

        return new JsonResponse([
            'data' => $data ?? (method_exists($paginator, 'items') ? $paginator->items() : $paginator),
            'meta' => (object) $meta,
            'message' => $message,
        ], $status, $headers);
    }

    /**
     * Standard error JSON response envelope.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message,
        string $code = 'ERROR',
        array $errors = [],
        int $status = Response::HTTP_BAD_REQUEST,
        array $headers = []
    ): JsonResponse {
        return new JsonResponse([
            'message' => $message,
            'errors' => (object) $errors,
            'code' => $code,
        ], $status, $headers);
    }
}
