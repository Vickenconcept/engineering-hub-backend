<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MilestoneController extends Controller
{
    public function __construct(
        protected readonly FileUploadService $uploadService
    ) {
    }

    /**
     * Submit milestone for approval (after completion)
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        // Block suspended companies
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. You cannot submit milestones.', 403);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->with(['project', 'escrow', 'evidence'])->findOrFail($id);

        // Allow resubmission if milestone was rejected (for revisions)
        if ($milestone->status !== Milestone::STATUS_FUNDED && $milestone->status !== Milestone::STATUS_REJECTED) {
            return $this->errorResponse('Milestone must be funded or rejected before submission', 400);
        }

        if (!$milestone->isFunded()) {
            return $this->errorResponse('Milestone escrow must be funded before submission', 400);
        }

        if (!$milestone->evidence()->exists()) {
            return $this->errorResponse('Milestone must have evidence before submission', 400);
        }

        $milestone->update([
            'status' => Milestone::STATUS_SUBMITTED,
        ]);

        // Log audit action
        app(AuditLogService::class)->logMilestoneAction('submitted', $milestone->id);

        return $this->successResponse(
            $milestone->load(['project', 'escrow', 'evidence']),
            'Milestone submitted for approval.'
        );
    }

    /**
     * Upload evidence for milestone.
     * Supports single file (file) or multiple files (files[]) in one request.
     */
    public function uploadEvidence(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->findOrFail($id);

        $hasSingleFile = $request->hasFile('file');
        $hasMultipleFiles = $request->hasFile('files') && is_array($request->file('files'));

        if ($hasSingleFile && $hasMultipleFiles) {
            return $this->errorResponse('Send either "file" or "files[]", not both.', 400);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:image,video,text'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,mp4,mov,avi', 'max:10240'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,mp4,mov,avi', 'max:10240'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        // For image/video, require at least one file (single or multiple)
        if (in_array($validated['type'], ['image', 'video'], true) && !$hasSingleFile && !$hasMultipleFiles) {
            return $this->errorResponse('At least one file is required for image or video evidence.', 422);
        }
        if ($hasMultipleFiles && count($request->file('files')) === 0) {
            return $this->errorResponse('At least one file is required in "files".', 422);
        }

        $folder = "engineering-hub/milestones/{$milestone->id}/evidence";
        $created = [];

        if ($hasMultipleFiles) {
            foreach ($request->file('files') as $uploadedFile) {
                try {
                    $result = $this->uploadService->uploadFile($uploadedFile, $folder);
                } catch (\Exception $e) {
                    return $this->errorResponse('Failed to upload file: ' . $e->getMessage(), 500);
                }
                $evidence = $milestone->evidence()->create([
                    'type' => $validated['type'],
                    'file_path' => null,
                    'url' => $result['url'],
                    'public_id' => $result['public_id'],
                    'thumbnail_url' => $result['thumbnail_url'] ?? null,
                    'description' => $validated['description'],
                    'uploaded_by' => $user->id,
                ]);
                $created[] = $evidence;
            }
            $count = count($created);
            return $this->createdResponse(
                $milestone->load(['evidence']),
                $count === 1 ? 'Evidence uploaded successfully.' : "{$count} files uploaded successfully."
            );
        }

        // Single file (existing behavior)
        $url = null;
        $publicId = null;
        $thumbnailUrl = null;
        if ($request->hasFile('file')) {
            try {
                $result = $this->uploadService->uploadFile($request->file('file'), $folder);
                $url = $result['url'];
                $publicId = $result['public_id'];
                $thumbnailUrl = $result['thumbnail_url'] ?? null;
            } catch (\Exception $e) {
                return $this->errorResponse('Failed to upload file: ' . $e->getMessage(), 500);
            }
        }

        $evidence = $milestone->evidence()->create([
            'type' => $validated['type'],
            'file_path' => null,
            'url' => $url,
            'public_id' => $publicId,
            'thumbnail_url' => $thumbnailUrl,
            'description' => $validated['description'],
            'uploaded_by' => $user->id,
        ]);

        return $this->createdResponse(
            $evidence->load(['milestone', 'uploader']),
            'Evidence uploaded successfully.'
        );
    }

    /**
     * Add or update company notes on a milestone
     */
    public function updateNotes(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        // Block suspended companies
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. You cannot update notes.', 403);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->findOrFail($id);

        $milestone->update([
            'company_notes' => $validated['notes'],
        ]);

        return $this->successResponse(
            $milestone->load(['project']),
            'Notes updated successfully.'
        );
    }

    /**
     * Get milestone details
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->with(['project', 'escrow', 'evidence', 'verifier'])->findOrFail($id);

        return $this->successResponse($milestone);
    }
}
