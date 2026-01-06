<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Consultation;
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
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $project = Project::where('client_id', $user->id)
            ->with([
                'company.user',
                'company',
                'milestones.escrow',
                'milestones.evidence',
                'disputes'
            ])
            ->findOrFail($id);

        return $this->successResponse($project, 'Project retrieved successfully');
    }

    /**
     * Create a new project from consultation
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consultation_id' => ['required', 'exists:consultations,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['required', 'string', 'max:255'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
        ]);

        $user = $request->user();
        
        // Verify consultation belongs to client and is completed
        $consultation = Consultation::where('client_id', $user->id)
            ->findOrFail($validated['consultation_id']);

        if (!$consultation->isCompleted()) {
            return $this->errorResponse('Consultation must be completed before creating a project', 400);
        }

        $project = Project::create([
            'client_id' => $user->id,
            'company_id' => $consultation->company_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'location' => $validated['location'],
            'budget_min' => $validated['budget_min'] ?? null,
            'budget_max' => $validated['budget_max'] ?? null,
            'status' => Project::STATUS_DRAFT,
        ]);

        return $this->createdResponse(
            $project->load(['company.user', 'company']),
            'Project created successfully. Add milestones to proceed.'
        );
    }
}
