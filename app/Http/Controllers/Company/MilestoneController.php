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
     * Fund a milestone (mark as ready for payment - client will initiate payment)
     */
    public function fund(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->with('project')->findOrFail($id);

        if ($milestone->status !== Milestone::STATUS_PENDING) {
            return $this->errorResponse('Only pending milestones can be funded', 400);
        }

        // Check if previous milestone is completed
        if (!$milestone->previousMilestoneCompleted()) {
            return $this->errorResponse('Previous milestone must be completed before funding this one', 400);
        }

        // Check if project has active disputes
        if ($milestone->project->hasActiveDispute()) {
            return $this->errorResponse('Cannot fund milestone while project has active disputes', 400);
        }

        $milestone->update([
            'status' => Milestone::STATUS_FUNDED,
        ]);

        // Log audit action
        app(AuditLogService::class)->logMilestoneAction('funded', $milestone->id, [
            'amount' => $milestone->amount,
        ]);

        return $this->successResponse(
            $milestone->load(['project']),
            'Milestone marked as ready for funding. Client can now deposit funds to escrow.'
        );
    }

    /**
     * Submit milestone for approval (after completion)
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $milestone = Milestone::whereHas('project', function ($query) use ($company) {
            $query->where('company_id', $company->id);
        })->with(['project', 'escrow', 'evidence'])->findOrFail($id);

        if ($milestone->status !== Milestone::STATUS_FUNDED) {
            return $this->errorResponse('Milestone must be funded before submission', 400);
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
    public function uploadEvidence(Request $request, int $id): JsonResponse
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
