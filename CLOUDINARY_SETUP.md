# Cloudinary Setup Guide

## Installation
✅ Cloudinary Laravel package has been installed: `cloudinary-labs/cloudinary-laravel`

## Configuration

### 1. Get Cloudinary Credentials
1. Sign up at [https://cloudinary.com](https://cloudinary.com) (free tier available)
2. Go to Dashboard → Settings → Access Keys
3. Copy your credentials:
   - Cloud Name
   - API Key
   - API Secret

### 2. Add to `.env` file
Add the following to your `.env` file:

```env
CLOUDINARY_URL=cloudinary://API_KEY:API_SECRET@CLOUD_NAME
```

Or set individual variables:
```env
CLOUDINARY_CLOUD_NAME=your_cloud_name
CLOUDINARY_KEY=your_api_key
CLOUDINARY_SECRET=your_api_secret
```

### 3. Configuration File
The configuration file has been published to `config/cloudinary.php`.

## Usage

### Backend
- **FileUploadService**: Handles all file uploads to Cloudinary
- **FileUploadController**: Provides `/api/upload` endpoint for general file uploads
- **Company/MilestoneController**: Uploads milestone evidence to Cloudinary
- **CompanyProfileController**: Uploads company license documents to Cloudinary

### Frontend
- Files are stored with Cloudinary URLs in the database
- The `getFileUrl()` utility function handles both Cloudinary URLs and local storage paths
- Evidence images and videos are displayed directly from Cloudinary

## Database Changes
- `milestone_evidence` table now has:
  - `url` (text) - Cloudinary URL
  - `public_id` (string) - Cloudinary public ID for deletion
  - `thumbnail_url` (string) - Video thumbnail URL

## File Storage Structure
Files are organized in Cloudinary folders:
- Milestone evidence: `engineering-hub/milestones/{milestone_id}/evidence`
- Company licenses: `engineering-hub/companies/licenses`

## API Endpoints

### Upload File
```
POST /api/upload
Content-Type: multipart/form-data

Body:
- file: (required) File to upload
- folder: (optional) Cloudinary folder path

Response:
{
  "success": true,
  "data": {
    "type": "image|video",
    "url": "https://res.cloudinary.com/...",
    "thumbnail_url": "https://...", // For videos
    "metadata": {
      "width": 1920,
      "height": 1080,
      "format": "jpg",
      "bytes": 123456,
      "public_id": "...",
      "duration": 30 // For videos
    }
  }
}
```

## Notes
- Maximum file size: 10MB (configurable in controllers)
- Supported formats: JPG, PNG, MP4, MOV, AVI, WEBM
- Videos automatically get thumbnail URLs generated
- Old `file_path` column is kept for backward compatibility but new uploads use `url`

