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

            // Build upload options
            $uploadOptions = array_merge([
                'folder' => $folder ?? 'engineering-hub',
                'resource_type' => $isVideo ? 'video' : 'image',
            ], $options);

            // Upload to Cloudinary
            $result = Cloudinary::upload($file->getRealPath(), $uploadOptions);

            // Extract metadata from result array
            $resultArray = $result->getArrayCopy();
            
            // Get secure URL
            $secureUrl = $result->getSecurePath();
            $publicId = $result->getPublicId();
            
            // Extract dimensions and other metadata
            $width = $resultArray['width'] ?? null;
            $height = $resultArray['height'] ?? null;
            $format = $resultArray['format'] ?? null;
            $bytes = $resultArray['bytes'] ?? null;
            $duration = $resultArray['duration'] ?? null; // For videos

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
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $mimeType,
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
            Cloudinary::destroy($publicId, [
                'resource_type' => $resourceType,
            ]);
            return true;
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

