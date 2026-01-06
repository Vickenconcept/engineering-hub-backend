<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    /**
     * List all disputes
     */
    public function index(Request $request): JsonResponse
    {
        $query = Dispute::with(['project', 'milestone', 'raisedBy']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $disputes = $query->latest()->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($disputes, 'Disputes retrieved successfully');
    }

    /**
     * Show a specific dispute
     */
    public function show(int $id): JsonResponse
    {
        $dispute = Dispute::with(['project', 'milestone', 'raisedBy'])->findOrFail($id);

        return $this->successResponse($dispute, 'Dispute retrieved successfully');
    }

    /**
     * Resolve a dispute
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => ['required', 'string', 'max:2000'],
            'status' => ['required', 'in:resolved,escalated'],
        ]);

        $dispute = Dispute::with(['project', 'milestone'])->findOrFail($id);

        if (!$dispute->isOpen()) {
            return $this->errorResponse('Dispute is already resolved or escalated', 400);
        }

        $dispute->update([
            'status' => $validated['status'],
            'resolution_notes' => $validated['resolution'],
        ]);

        // Log audit action
        app(AuditLogService::class)->logDisputeAction('resolved', $dispute->id, [
            'resolved_by' => auth()->id(),
            'status' => $validated['status'],
        ]);

        return $this->successResponse(
            $dispute->load(['project', 'milestone', 'raisedBy']),
            'Dispute resolved successfully.'
        );
    }
}
