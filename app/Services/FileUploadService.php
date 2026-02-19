<?php

namespace App\Services;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FileUploadService
{
    /**
     * Upload a file to Cloudinary
     * 
     * @param UploadedFile $file
     * @param string|null $folder Optional folder path in Cloudinary
     * @param array $options Additional Cloudinary options
     * @return array Upload result with url, public_id, and metadata
     * @throws \Exception
     */
    public function uploadFile(UploadedFile $file, ?string $folder = null, array $options = []): array
    {
        try {
            $mimeType = $file->getMimeType();
            $isImage = str_starts_with($mimeType, 'image/');
            $isVideo = str_starts_with($mimeType, 'video/');
            $isRaw = !$isImage && !$isVideo;
            $originalName = $file->getClientOriginalName();
            $originalExtension = strtolower((string) $file->getClientOriginalExtension());

            // Build upload options
            $uploadOptions = array_merge([
                'folder' => $folder ?? 'engineering-hub',
                'resource_type' => $isVideo ? 'video' : ($isRaw ? 'raw' : 'image'),
                // Preserve original filename when possible so raw files keep their extensions (e.g., .pdf)
                'use_filename' => true,
                'unique_filename' => true,
            ], $options);

            if ($isRaw && $originalExtension) {
                $uploadOptions['format'] = $originalExtension;
            }

            // Upload to Cloudinary using uploadApi
            $cloudinary = Cloudinary::getFacadeRoot();
            $result = $cloudinary->uploadApi()->upload($file->getRealPath(), $uploadOptions);

            // The result from uploadApi()->upload() is an array
            // Extract data from the result array
            $secureUrl = $result['secure_url'] ?? $result['url'] ?? null;
            $publicId = $result['public_id'] ?? null;
            $width = $result['width'] ?? null;
            $height = $result['height'] ?? null;
            $format = $result['format'] ?? null;
            $bytes = $result['bytes'] ?? null;
            $duration = $result['duration'] ?? null; // For videos
            
            if (!$secureUrl) {
                throw new \Exception('Upload succeeded but no URL was returned from Cloudinary');
            }

            // Generate thumbnail URL for videos
            $thumbnailUrl = null;
            if ($isVideo && $publicId) {
                // Use Cloudinary URL transformation for video thumbnail
                $thumbnailUrl = str_replace(
                    ['/upload/', '/upload/v'],
                    ['/upload/w_640,h_360,c_fill,f_jpg/', '/upload/w_640,h_360,c_fill,f_jpg/v'],
                    $secureUrl
                );
            }

            return [
                'secure_url' => $secureUrl,
                'url' => $secureUrl, // Alias for secure_url
                'public_id' => $publicId,
                'thumbnail_url' => $thumbnailUrl,
                'width' => $width,
                'height' => $height,
                'duration' => $duration,
                'format' => $format,
                'bytes' => $bytes,
            ];
        } catch (\Exception $e) {
            Log::error('FileUploadService: Upload failed', [
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'file_name' => $file->getClientOriginalName() ?? 'unknown',
                'file_size' => $file->getSize() ?? null,
                'mime_type' => $mimeType ?? ($file->getMimeType() ?? 'unknown'),
            ]);
            throw new \Exception('Failed to upload file: ' . $e->getMessage());
        }
    }

    /**
     * Delete a file from Cloudinary
     * 
     * @param string $publicId The public ID of the file in Cloudinary
     * @param string $resourceType 'image' or 'video'
     * @return bool
     */
    public function deleteFile(string $publicId, string $resourceType = 'image'): bool
    {
        try {
            $cloudinary = Cloudinary::getFacadeRoot();
            $result = $cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => $resourceType,
            ]);
            return isset($result['result']) && $result['result'] === 'ok';
        } catch (\Exception $e) {
            Log::error('FileUploadService: Delete failed', [
                'public_id' => $publicId,
                'resource_type' => $resourceType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

