<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Project;
use App\Notifications\DisputeCreatedNotification;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    /**
     * Create a new dispute (accessible by client or company)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'milestone_id' => ['nullable', 'exists:milestones,id'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        
        $project = Project::findOrFail($validated['project_id']);

        // Authorization: Only client or company can create disputes
        $canCreate = $project->client_id === $user->id;
        
        if (!$canCreate && $user->isCompany()) {
            $canCreate = $project->company_id === $user->company?->id;
        }

        if (!$canCreate) {
            return $this->forbiddenResponse('You do not have permission to create disputes for this project');
        }

        // Verify milestone belongs to project if provided
        if ($validated['milestone_id']) {
            $milestone = $project->milestones()->find($validated['milestone_id']);
            if (!$milestone) {
                return $this->errorResponse('Milestone does not belong to this project', 400);
            }
        }

        $dispute = Dispute::create([
            'project_id' => $validated['project_id'],
            'milestone_id' => $validated['milestone_id'] ?? null,
            'type' => Dispute::TYPE_DISPUTE,
            'raised_by' => $user->id,
            'reason' => $validated['reason'],
            'status' => Dispute::STATUS_OPEN,
        ]);

        // Update project status if needed
        if ($project->status !== Project::STATUS_DISPUTED) {
            $project->update(['status' => Project::STATUS_DISPUTED]);
        }

        // Log audit action
        app(AuditLogService::class)->logDisputeAction('created', $dispute->id, [
            'raised_by' => $user->id,
            'project_id' => $project->id,
        ]);

        $dispute->load(['project.client', 'project.company.user', 'milestone']);

        // Send notifications
        $dispute->project->client->notify(new DisputeCreatedNotification($dispute));
        if ($dispute->project->company->user) {
            $dispute->project->company->user->notify(new DisputeCreatedNotification($dispute));
        }

        return $this->createdResponse(
            $dispute->load(['project', 'milestone', 'raisedBy']),
            'Dispute created successfully. Admin will review it.'
        );
    }
}
