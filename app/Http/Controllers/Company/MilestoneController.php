<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MilestoneController extends Controller
{

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
            'Milestone submitted successfully. Awaiting client approval.'
        );
    }

    /**
     * Upload evidence for milestone
     */
    public function uploadEvidence(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:image,video,text'],
            'file' => ['required_if:type,image,video', 'file', 'mimes:jpg,jpeg,png,mp4,mov,avi', 'max:10240'], // 10MB max
            'description' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->findOrFail($id);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store("milestones/{$milestone->id}/evidence", 'public');
        }

        $evidence = $milestone->evidence()->create([
            'type' => $validated['type'],
            'file_path' => $filePath,
            'description' => $validated['description'],
            'uploaded_by' => $user->id,
        ]);

        return $this->createdResponse(
            $evidence->load(['milestone', 'uploader']),
            'Evidence uploaded successfully.'
        );
    }
}
