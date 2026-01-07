<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * List company's projects
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $projects = Project::where('company_id', $company->id)
            ->with(['client', 'milestones.escrow'])
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
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $project = Project::where('company_id', $company->id)
            ->with([
                'client',
                'milestones.escrow',
                'milestones.evidence',
                'disputes'
            ])
            ->findOrFail($id);

        return $this->successResponse($project, 'Project retrieved successfully');
    }

    /**
     * Create milestones for a project
     */
    public function createMilestones(\App\Http\Requests\Milestone\CreateMilestoneRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $project = Project::where('company_id', $company->id)
            ->findOrFail($id);

        if ($project->status !== Project::STATUS_DRAFT) {
            return $this->errorResponse('Can only add milestones to draft projects', 400);
        }

        $validated = $request->validated();

        $milestones = [];
        foreach ($validated['milestones'] as $milestoneData) {
            $milestones[] = Milestone::create([
                'project_id' => $project->id,
                'title' => $milestoneData['title'],
                'description' => $milestoneData['description'] ?? null,
                'amount' => $milestoneData['amount'],
                'sequence_order' => $milestoneData['sequence_order'],
                'status' => Milestone::STATUS_PENDING,
            ]);
        }

        // Activate project after milestones are created
        $project->update(['status' => Project::STATUS_ACTIVE]);

        return $this->createdResponse(
            $project->load('milestones'),
            'Milestones created successfully. Project is now active.'
        );
    }
}
