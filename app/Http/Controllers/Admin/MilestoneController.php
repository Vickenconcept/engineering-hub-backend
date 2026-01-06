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
     * Release escrow funds for a milestone
     */
    public function release(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'override' => ['nullable', 'boolean'], // Admin override even without client approval
        ]);

        $milestone = Milestone::with(['project', 'escrow'])->findOrFail($id);

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

        // TODO: Implement actual payment release through payment provider
        // For now, we'll just mark it as released
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
        ]);

        return $this->successResponse(
            $milestone->load(['project', 'escrow']),
            'Escrow funds released successfully.'
        );
    }
}
