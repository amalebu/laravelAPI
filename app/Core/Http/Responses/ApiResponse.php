<?php

namespace App\Core\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Permintaan berhasil diproses.',
        int $statusCode = 200
    ): JsonResponse {
        $response = response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);

        return self::withContentLength($response);
    }

    public static function created(
        mixed $data = null,
        string $message = 'Data berhasil dibuat.'
    ): JsonResponse {
        return self::success($data, $message, 201);
    }

    public static function paginated(
        LengthAwarePaginator $paginator,
        string $resourceClass,
        string $message = 'Data berhasil diambil.'
    ): JsonResponse {
        $response = response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resourceClass::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 200);

        return self::withContentLength($response);
    }

    public static function error(
        string $message = 'Terjadi kesalahan pada permintaan.',
        int $statusCode = 400,
        mixed $errors = null
    ): JsonResponse {
        $response = response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);

        return self::withContentLength($response);
    }

    private static function withContentLength(JsonResponse $response): JsonResponse
    {
        $content = $response->getContent();
        if (is_string($content)) {
            $response->header('Content-Length', (string) strlen($content));
        }

        return $response;
    }
}
