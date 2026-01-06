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
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $project = Project::with([
            'client',
            'company.user',
            'milestones.escrow',
            'milestones.evidence',
            'disputes'
        ])->findOrFail($id);

        // Authorization: Only client or company can view
        $canView = $project->client_id === $user->id;
        
        if (!$canView && $user->isCompany()) {
            $canView = $project->company_id === $user->company?->id;
        }

        if (!$canView && !$user->isAdmin()) {
            return $this->forbiddenResponse('You do not have permission to view this project');
        }

        return $this->successResponse($project, 'Project retrieved successfully');
    }
}
