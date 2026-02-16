<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\Milestone;
use App\Notifications\ProjectCompletedNotification;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        protected readonly FileUploadService $uploadService
    ) {
    }

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

        // Block suspended companies from accessing projects
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. Please contact support to appeal.', 403);
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
                'documents',
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

        // Block suspended companies
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. You cannot create milestones.', 403);
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

        // Don't activate project yet - wait for client to verify all milestones
        // Project will be activated automatically when all milestones are verified

        return $this->createdResponse(
            $project->load('milestones'),
            'Milestones created successfully. The project will become active once the client verifies all milestones.'
        );
    }

    /**
     * Mark project as completed (company)
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        // Block suspended companies
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. You cannot complete projects.', 403);
        }

        $project = Project::where('company_id', $company->id)
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
        app(AuditLogService::class)->logProjectAction('completed', $project->id, [
            'completed_by' => $user->id,
            'reason' => 'Manually completed by company',
            'auto_completed' => false,
        ]);

        $project->load(['client', 'company.user']);

        // Send notifications
        $project->client->notify(new ProjectCompletedNotification($project));
        if ($project->company->user) {
            $project->company->user->notify(new ProjectCompletedNotification($project));
        }

        return $this->successResponse(
            $project->load(['client', 'milestones.escrow']),
            'Project marked as completed successfully.'
        );
    }

    /**
     * Upload project documents (company only)
     */
    public function uploadDocuments(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. You cannot upload documents.', 403);
        }

        $project = Project::where('company_id', $company->id)
            ->with('documents')
            ->findOrFail($id);

        $validated = $request->validate([
            'preview_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'drawing_architectural' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'drawing_structural' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'drawing_mechanical' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'drawing_technical' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'extra_titles' => ['nullable', 'array'],
            'extra_titles.*' => ['string', 'max:255'],
            'extra_files' => ['nullable', 'array'],
            'extra_files.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $extraTitles = $request->input('extra_titles', []);
        $extraFiles = $request->file('extra_files', []);

        if (!empty($extraTitles) && !empty($extraFiles) && count($extraTitles) !== count($extraFiles)) {
            return $this->errorResponse('Extra document titles and files count do not match.', 422);
        }

        $folder = "engineering-hub/projects/{$project->id}/documents";

        $updates = [];

        if ($request->hasFile('preview_image')) {
            $result = $this->uploadService->uploadFile($request->file('preview_image'), $folder);
            $updates['preview_image_url'] = $result['url'];
        }

        if ($request->hasFile('drawing_architectural')) {
            $result = $this->uploadService->uploadFile($request->file('drawing_architectural'), $folder);
            $updates['drawing_architectural_url'] = $result['url'];
        }

        if ($request->hasFile('drawing_structural')) {
            $result = $this->uploadService->uploadFile($request->file('drawing_structural'), $folder);
            $updates['drawing_structural_url'] = $result['url'];
        }

        if ($request->hasFile('drawing_mechanical')) {
            $result = $this->uploadService->uploadFile($request->file('drawing_mechanical'), $folder);
            $updates['drawing_mechanical_url'] = $result['url'];
        }

        if ($request->hasFile('drawing_technical')) {
            $result = $this->uploadService->uploadFile($request->file('drawing_technical'), $folder);
            $updates['drawing_technical_url'] = $result['url'];
        }

        if (!empty($updates)) {
            $project->update($updates);
        }

        if (!empty($extraFiles)) {
            foreach ($extraFiles as $index => $file) {
                $title = $extraTitles[$index] ?? $file->getClientOriginalName();
                $result = $this->uploadService->uploadFile($file, $folder);

                ProjectDocument::create([
                    'project_id' => $project->id,
                    'uploaded_by' => $user->id,
                    'title' => $title,
                    'file_url' => $result['url'],
                ]);
            }
        }

        return $this->successResponse(
            $project->fresh()->load('documents'),
            'Project documents updated successfully.'
        );
    }
}
