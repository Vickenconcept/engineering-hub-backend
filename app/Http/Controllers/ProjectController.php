<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Show a project (accessible by client or company associated with project)
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $project = Project::with([
            'client',
            'company.user',
            'documents',
            'milestones.escrow',
            'milestones.evidence',
            'disputes',
            'documentUpdateRequests.requestedBy.company',
            'documentUpdateRequests.extraDocument'
        ])->findOrFail($id);

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
        
        $project = $projectArray;

        // Authorization: Client, company, or admin can view
        $canView = false;
        
        // Admin can view any project
        if ($user->isAdmin()) {
            $canView = true;
        }
        // Client can view their own projects
        elseif ($project->client_id === $user->id) {
            $canView = true;
        }
        // Company can view projects assigned to them
        elseif ($user->isCompany() && $project->company_id === $user->company?->id) {
            $canView = true;
        }

        if (!$canView) {
            return $this->forbiddenResponse('You do not have permission to view this project');
        }

        return $this->successResponse($project, 'Project retrieved successfully');
    }
}
