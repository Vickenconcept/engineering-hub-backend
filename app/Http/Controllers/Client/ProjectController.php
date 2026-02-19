<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\CreateProjectRequest;
use App\Models\Project;
use App\Models\Consultation;
use App\Models\Milestone;
use App\Models\DocumentUpdateRequest;
use App\Notifications\ProjectCompletedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * List client's projects
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $projects = Project::where('client_id', $user->id)
            ->with(['company.user', 'company', 'milestones.escrow'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($projects, 'Projects retrieved successfully');
    }

    /**
     * Show a specific project
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $project = Project::where('client_id', $user->id)
            ->with([
                'company.user',
                'company',
                'documents',
                'milestones.escrow',
                'milestones.evidence',
                'disputes',
                'documentUpdateRequests.requestedBy.company',
                'documentUpdateRequests.extraDocument'
            ])
            ->findOrFail($id);

        // Convert to array to ensure relationships are included
        $projectArray = $project->toArray();
        
        // Manually add documentUpdateRequests if it exists
        if ($project->relationLoaded('documentUpdateRequests') && $project->documentUpdateRequests) {
            $projectArray['document_update_requests'] = $project->documentUpdateRequests->map(function ($request) {
                return [
                    'id' => $request->id,
                    'project_id' => $request->project_id,
                    'document_type' => $request->document_type,
                    'extra_document_id' => $request->extra_document_id,
                    'requested_by' => $request->requestedBy ? [
                        'id' => $request->requestedBy->id,
                        'name' => $request->requestedBy->name,
                        'email' => $request->requestedBy->email,
                    ] : null,
                    'company' => $request->requestedBy && $request->requestedBy->company ? [
                        'id' => $request->requestedBy->company->id,
                        'company_name' => $request->requestedBy->company->company_name,
                    ] : null,
                    'status' => $request->status,
                    'reason' => $request->reason,
                    'granted_at' => $request->granted_at?->toISOString(),
                    'denied_at' => $request->denied_at?->toISOString(),
                    'extra_document' => $request->extraDocument ? [
                        'id' => $request->extraDocument->id,
                        'title' => $request->extraDocument->title,
                        'file_url' => $request->extraDocument->file_url,
                    ] : null,
                    'created_at' => $request->created_at?->toISOString(),
                    'updated_at' => $request->updated_at?->toISOString(),
                ];
            })->toArray();
        } else {
            $projectArray['document_update_requests'] = [];
        }

        return $this->successResponse($projectArray, 'Project retrieved successfully');
    }

    /**
     * Create a new project from consultation
     */
    public function store(CreateProjectRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        
        // Verify consultation belongs to client and is completed
        $consultation = Consultation::where('client_id', $user->id)
            ->findOrFail($validated['consultation_id']);

        if (!$consultation->isCompleted()) {
            return $this->errorResponse('Consultation must be completed before creating a project', 400);
        }

        $location = $validated['location'] ?? null;
        if (!$location && !empty($validated['location_address']) && !empty($validated['location_state']) && !empty($validated['location_country'])) {
            $location = sprintf(
                '%s, %s, %s',
                $validated['location_address'],
                $validated['location_state'],
                $validated['location_country']
            );
        }

        $project = Project::create([
            'client_id' => $user->id,
            'company_id' => $consultation->company_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'location' => $location ?? '',
            'location_country' => $validated['location_country'] ?? null,
            'location_state' => $validated['location_state'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'budget_min' => $validated['budget_min'] ?? null,
            'budget_max' => $validated['budget_max'] ?? null,
            'status' => Project::STATUS_DRAFT,
        ]);

        return $this->createdResponse(
            $project->load(['company.user', 'company']),
            'Project created successfully. Add milestones to proceed.'
        );
    }

    /**
     * Mark project as completed (client)
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $project = Project::where('client_id', $user->id)
            ->findOrFail($id);

        if ($project->status === Project::STATUS_COMPLETED) {
            return $this->errorResponse('Project is already completed', 400);
        }

        // Check if all milestones are released (optional validation)
        $allMilestonesReleased = $project->milestones()
            ->whereNotIn('status', [Milestone::STATUS_RELEASED])
            ->doesntExist();

        if (!$allMilestonesReleased) {
            return $this->errorResponse('All milestones must be released before completing the project', 400);
        }

        $project->update([
            'status' => Project::STATUS_COMPLETED,
        ]);

        // Log audit action
        app(\App\Services\AuditLogService::class)->logProjectAction('completed', $project->id, [
            'completed_by' => $user->id,
            'reason' => 'Manually completed by client',
            'auto_completed' => false,
        ]);

        $project->load(['client', 'company.user']);

        // Send notifications
        $project->client->notify(new ProjectCompletedNotification($project));
        if ($project->company->user) {
            $project->company->user->notify(new ProjectCompletedNotification($project));
        }

        return $this->successResponse(
            $project->load(['company.user', 'company', 'milestones.escrow']),
            'Project marked as completed successfully.'
        );
    }

    /**
     * Grant a document update request
     */
    public function grantDocumentUpdate(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $updateRequest = DocumentUpdateRequest::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id);
        })
        ->findOrFail($id);

        if ($updateRequest->status !== DocumentUpdateRequest::STATUS_PENDING) {
            return $this->errorResponse('This request has already been processed.', 400);
        }

        $updateRequest->update([
            'status' => DocumentUpdateRequest::STATUS_GRANTED,
            'granted_by' => $user->id,
            'granted_at' => now(),
        ]);

        // TODO: Send notification to company

        return $this->successResponse(
            $updateRequest->load(['requestedBy', 'project', 'grantedBy']),
            'Document update request granted successfully.'
        );
    }

    /**
     * Deny a document update request
     */
    public function denyDocumentUpdate(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $updateRequest = DocumentUpdateRequest::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id);
        })
        ->findOrFail($id);

        if ($updateRequest->status !== DocumentUpdateRequest::STATUS_PENDING) {
            return $this->errorResponse('This request has already been processed.', 400);
        }

        $updateRequest->update([
            'status' => DocumentUpdateRequest::STATUS_DENIED,
            'granted_by' => $user->id,
            'denied_at' => now(),
        ]);

        // TODO: Send notification to company

        return $this->successResponse(
            $updateRequest->load(['requestedBy', 'project', 'grantedBy']),
            'Document update request denied.'
        );
    }
}
