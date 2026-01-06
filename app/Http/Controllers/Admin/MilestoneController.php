<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    /**
     * List milestones with escrow funds
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $status = $request->input('status', 'held'); // held, released, all
        
        $query = Milestone::with(['project', 'escrow', 'project.company', 'project.client'])
            ->whereHas('escrow');
        
        if ($status !== 'all') {
            $query->whereHas('escrow', function ($q) use ($status) {
                $q->where('status', $status);
            });
        }
        
        $milestones = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
        
        return $this->successResponse($milestones, 'Milestones with escrow retrieved successfully.');
    }
    
    /**
     * Release escrow funds for a milestone
     */
    public function release(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'override' => ['nullable', 'boolean'], // Admin override even without client approval
            'recipient_account' => ['required', 'array'],
            'recipient_account.account_number' => ['required', 'string'],
            'recipient_account.bank_code' => ['required', 'string'],
            'recipient_account.name' => ['required', 'string'],
        ]);

        $milestone = Milestone::with(['project', 'escrow', 'project.company'])->findOrFail($id);

        if (!$milestone->escrow) {
            return $this->errorResponse('No escrow found for this milestone', 400);
        }

        if ($milestone->escrow->status !== Escrow::STATUS_HELD) {
            return $this->errorResponse('Escrow is not in held status', 400);
        }

        // Business rule: Milestone must be approved OR admin override
        if ($milestone->status !== Milestone::STATUS_APPROVED && !($validated['override'] ?? false)) {
            return $this->errorResponse(
                'Milestone must be approved by client or use admin override',
                400
            );
        }

        $paymentService = app(\App\Services\Payment\PaymentServiceInterface::class);

        try {
            // Release funds to company account
            $releaseData = $paymentService->releaseFunds(
                reference: $milestone->escrow->payment_reference,
                recipientAccount: $validated['recipient_account']
            );

            // Update escrow and milestone status
            $milestone->escrow->update([
                'status' => Escrow::STATUS_RELEASED,
            ]);

            $milestone->update([
                'status' => Milestone::STATUS_RELEASED,
            ]);

            // Log audit action
            app(AuditLogService::class)->logEscrowAction('released', $milestone->escrow->id, [
                'released_by' => auth()->id(),
                'amount' => $milestone->escrow->amount,
                'admin_override' => $validated['override'] ?? false,
                'transfer_reference' => $releaseData['transfer_reference'] ?? null,
            ]);

            return $this->successResponse(
                [
                    'milestone' => $milestone->load(['project', 'escrow']),
                    'transfer' => $releaseData,
                ],
                'Escrow funds released successfully.'
            );
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to release funds: ' . $e->getMessage(), 500);
        }
    }
}
