<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Standardized API Response Helper
 * 
 * Provides a consistent response format across all API endpoints
 * Format: { success, message, data, errors, meta }
 */
class ApiResponse
{
    /**
     * Success response with data
     */
    public static function success(
        mixed $data = null,
        string $message = 'Operation successful',
        int $statusCode = Response::HTTP_OK,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Success response for resource creation
     */
    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully',
        array $meta = []
    ): JsonResponse {
        return self::success($data, $message, Response::HTTP_CREATED, $meta);
    }

    /**
     * Error response
     */
    public static function error(
        string $message = 'An error occurred',
        int $statusCode = Response::HTTP_BAD_REQUEST,
        mixed $errors = null,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Validation error response
     */
    public static function validationError(
        mixed $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return self::error($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    /**
     * Unauthorized response
     */
    public static function unauthorized(
        string $message = 'Unauthorized access'
    ): JsonResponse {
        return self::error($message, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Forbidden response
     */
    public static function forbidden(
        string $message = 'Forbidden'
    ): JsonResponse {
        return self::error($message, Response::HTTP_FORBIDDEN);
    }

    /**
     * Not found response
     */
    public static function notFound(
        string $message = 'Resource not found'
    ): JsonResponse {
        return self::error($message, Response::HTTP_NOT_FOUND);
    }

    /**
     * Paginated response
     */
    public static function paginated(
        mixed $data,
        string $message = 'Data retrieved successfully',
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data->items(),
            'meta' => array_merge([
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
                'from' => $data->firstItem(),
                'to' => $data->lastItem(),
            ], $meta),
        ];

        return response()->json($response, Response::HTTP_OK);
    }

    /**
     * No content response
     */
    public static function noContent(
        string $message = 'Operation completed successfully'
    ): JsonResponse {
        return self::success(null, $message, Response::HTTP_NO_CONTENT);
    }
}

