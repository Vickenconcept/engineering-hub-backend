<?php

namespace App\Http\Traits;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Trait for controllers to use standardized API responses
 * 
 * Usage in controllers:
 * use ApiResponseTrait;
 * 
 * Then: $this->success($data) or $this->error($message)
 */
trait ApiResponseTrait
{
    /**
     * Return success response
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'Operation successful',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        return ApiResponse::success($data, $message, $statusCode, $meta);
    }

    /**
     * Return created response
     */
    protected function createdResponse(
        mixed $data = null,
        string $message = 'Resource created successfully',
        array $meta = []
    ): JsonResponse {
        return ApiResponse::created($data, $message, $meta);
    }

    /**
     * Return error response
     */
    protected function errorResponse(
        string $message = 'An error occurred',
        int $statusCode = 400,
        mixed $errors = null,
        array $meta = []
    ): JsonResponse {
        return ApiResponse::error($message, $statusCode, $errors, $meta);
    }

    /**
     * Return validation error response
     */
    protected function validationErrorResponse(
        mixed $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return ApiResponse::validationError($errors, $message);
    }

    /**
     * Return unauthorized response
     */
    protected function unauthorizedResponse(
        string $message = 'Unauthorized access'
    ): JsonResponse {
        return ApiResponse::unauthorized($message);
    }

    /**
     * Return forbidden response
     */
    protected function forbiddenResponse(
        string $message = 'Forbidden'
    ): JsonResponse {
        return ApiResponse::forbidden($message);
    }

    /**
     * Return not found response
     */
    protected function notFoundResponse(
        string $message = 'Resource not found'
    ): JsonResponse {
        return ApiResponse::notFound($message);
    }

    /**
     * Return paginated response
     */
    protected function paginatedResponse(
        mixed $data,
        string $message = 'Data retrieved successfully',
        array $meta = []
    ): JsonResponse {
        return ApiResponse::paginated($data, $message, $meta);
    }

    /**
     * Return no content response
     */
    protected function noContentResponse(
        string $message = 'Operation completed successfully'
    ): JsonResponse {
        return ApiResponse::noContent($message);
    }
}

