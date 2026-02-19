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
            'documentUpdateRequests.requestedBy',
            'documentUpdateRequests.extraDocument'
        ])->findOrFail($id);

        // Convert to array to ensure relationships are included
        $projectArray = $project->toArray();
        // Manually add documentUpdateRequests if it exists
        if ($project->relationLoaded('documentUpdateRequests')) {
            $projectArray['document_update_requests'] = $project->documentUpdateRequests->toArray();
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
