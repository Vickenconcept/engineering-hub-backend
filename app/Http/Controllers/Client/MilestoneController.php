<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    /**
     * Fund milestone escrow (deposit payment)
     */
    public function fundEscrow(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $milestone = Milestone::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id);
        })->with(['project', 'escrow'])->findOrFail($id);

        if ($milestone->status !== Milestone::STATUS_PENDING) {
            return $this->errorResponse('Only pending milestones can be funded', 400);
        }

        // Check if escrow already exists
        if ($milestone->escrow) {
            return $this->errorResponse('Escrow already funded for this milestone', 400);
        }

        // Check if previous milestone is completed
        if (!$milestone->previousMilestoneCompleted()) {
            return $this->errorResponse('Previous milestone must be completed before funding this one', 400);
        }

        // Check if project has active disputes
        if ($milestone->project->hasActiveDispute()) {
            return $this->errorResponse('Cannot fund milestone while project has active disputes', 400);
        }

        $paymentService = app(\App\Services\Payment\PaymentServiceInterface::class);

        try {
            // Initialize payment for escrow
            $paymentData = $paymentService->initializePayment(
                amount: (float) $milestone->amount,
                currency: 'NGN',
                metadata: [
                    'email' => $user->email,
                    'milestone_id' => $milestone->id,
                    'project_id' => $milestone->project_id,
                    'type' => 'milestone_escrow',
                    'callback_url' => config('app.url') . '/payment/callback',
                ]
            );

            return $this->successResponse([
                'payment_url' => $paymentData['authorization_url'],
                'reference' => $paymentData['reference'],
                'milestone' => $milestone,
            ], 'Payment initialized. Redirect to payment_url to fund escrow.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to initialize payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve a milestone
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $milestone = Milestone::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id);
        })->with(['project', 'escrow'])->findOrFail($id);

        if ($milestone->status !== Milestone::STATUS_SUBMITTED) {
            return $this->errorResponse('Milestone must be submitted before approval', 400);
        }

        if (!$milestone->evidence()->exists()) {
            return $this->errorResponse('Milestone must have evidence before approval', 400);
        }

        // Check if project has active disputes
        if ($milestone->project->hasActiveDispute()) {
            return $this->errorResponse('Cannot approve milestone while project has active disputes', 400);
        }

        $milestone->update([
            'status' => Milestone::STATUS_APPROVED,
        ]);

        // Log audit action
        app(AuditLogService::class)->logMilestoneAction('approved', $milestone->id, [
            'approved_by' => $user->id,
            'project_id' => $milestone->project_id,
        ]);

        return $this->successResponse(
            $milestone->load(['project', 'escrow', 'evidence']),
            'Milestone approved successfully. Funds can now be released.'
        );
    }

    /**
     * Reject a milestone
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        
        $milestone = Milestone::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id);
        })->with(['project'])->findOrFail($id);

        if ($milestone->status !== Milestone::STATUS_SUBMITTED) {
            return $this->errorResponse('Only submitted milestones can be rejected', 400);
        }

        $milestone->update([
            'status' => Milestone::STATUS_REJECTED,
        ]);

        // Create dispute automatically on rejection (revision request)
        $dispute = $milestone->project->disputes()->create([
            'milestone_id' => $milestone->id,
            'type' => \App\Models\Dispute::TYPE_REVISION_REQUEST,
            'raised_by' => $user->id,
            'reason' => $validated['reason'],
            'status' => \App\Models\Dispute::STATUS_OPEN,
        ]);

        // Log audit action
        app(AuditLogService::class)->logMilestoneAction('rejected', $milestone->id, [
            'rejected_by' => $user->id,
            'reason' => $validated['reason'],
            'dispute_id' => $dispute->id,
        ]);

        return $this->successResponse(
            $milestone->load(['project', 'disputes']),
            'Milestone rejected. A dispute has been created for review.'
        );
    }

    /**
     * Verify a milestone (before project becomes active)
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        
        $milestone = Milestone::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id)
                  ->where('status', \App\Models\Project::STATUS_DRAFT);
        })->with(['project'])->findOrFail($id);

        if ($milestone->isVerified()) {
            return $this->errorResponse('Milestone is already verified', 400);
        }

        // Verify the milestone
        $milestone->update([
            'verified_at' => now(),
            'verified_by' => $user->id,
            'client_notes' => $validated['notes'] ?? null,
        ]);

        // Check if all milestones are verified, then activate project
        $project = $milestone->project;
        $allMilestonesVerified = $project->milestones()
            ->whereNull('verified_at')
            ->doesntExist();

        if ($allMilestonesVerified) {
            $project->update(['status' => \App\Models\Project::STATUS_ACTIVE]);
            
            // Log audit action
            app(AuditLogService::class)->logProjectAction('activated', $project->id, [
                'activated_by' => $user->id,
                'reason' => 'All milestones verified by client',
            ]);
        }

        // Log audit action
        app(AuditLogService::class)->logMilestoneAction('verified', $milestone->id, [
            'verified_by' => $user->id,
            'project_id' => $milestone->project_id,
            'project_activated' => $allMilestonesVerified,
        ]);

        return $this->successResponse(
            $milestone->load(['project', 'verifier']),
            $allMilestonesVerified 
                ? 'Milestone verified. All milestones are now verified. Project is now active!'
                : 'Milestone verified successfully.'
        );
    }

    /**
     * Add or update client notes on a milestone
     */
    public function updateNotes(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        
        $milestone = Milestone::whereHas('project', function ($query) use ($user) {
            $query->where('client_id', $user->id);
        })->findOrFail($id);

        $milestone->update([
            'client_notes' => $validated['notes'],
        ]);

        return $this->successResponse(
            $milestone->load(['project']),
            'Notes updated successfully.'
        );
    }
}
