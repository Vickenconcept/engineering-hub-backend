<?php

namespace App\Http\Controllers;

use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FileUploadController extends Controller
{
    public function __construct(
        protected readonly FileUploadService $uploadService
    ) {
    }

    /**
     * Upload a file (image or video) to Cloudinary
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,mp4,mov,avi,webm', 'max:10240'], // 10MB max
            'folder' => ['nullable', 'string', 'max:255'], // Optional folder path
        ]);

        $startTime = microtime(true);
        $user = $request->user();
        $file = $validated['file'];
        $fileSize = $file->getSize();
        $fileName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $type = str_starts_with($mimeType, 'image/') ? 'image' : 'video';

        Log::info('FileUploadController: Upload request received', [
            'user_id' => $user->id,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            'mime_type' => $mimeType,
            'type' => $type,
            'request_time' => now()->toIso8601String(),
        ]);

        try {
            $uploadStartTime = microtime(true);
            
            // Build folder path
            $folder = $validated['folder'] ?? 'engineering-hub';
            
            $result = $this->uploadService->uploadFile($file, $folder);
            
            $uploadEndTime = microtime(true);
            $uploadDuration = round($uploadEndTime - $uploadStartTime, 2);
            $totalDuration = round($uploadEndTime - $startTime, 2);

            Log::info('FileUploadController: Upload successful', [
                'user_id' => $user->id,
                'type' => $type,
                'public_id' => $result['public_id'],
                'url' => $result['url'],
                'upload_duration_seconds' => $uploadDuration,
                'total_duration_seconds' => $totalDuration,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'upload_speed_mbps' => $fileSize > 0 ? round(($fileSize / 1024 / 1024) / $uploadDuration, 2) : 0,
            ]);

            return $this->successResponse([
                'type' => $type,
                'url' => $result['url'],
                'thumbnail_url' => $result['thumbnail_url'] ?? null,
                'metadata' => [
                    'width' => $result['width'] ?? null,
                    'height' => $result['height'] ?? null,
                    'duration' => $result['duration'] ?? null,
                    'format' => $result['format'] ?? null,
                    'bytes' => $result['bytes'] ?? null,
                    'public_id' => $result['public_id'] ?? null,
                ],
            ], 'File uploaded successfully', 201);
        } catch (\Exception $e) {
            $errorDuration = round(microtime(true) - $startTime, 2);
            
            Log::error('FileUploadController: Upload failed', [
                'user_id' => $user->id,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'type' => $type,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'duration_before_error_seconds' => $errorDuration,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Upload failed: ' . $e->getMessage(), 500);
        }
    }
}

