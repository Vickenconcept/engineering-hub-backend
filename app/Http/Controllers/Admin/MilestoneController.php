<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Models\Project;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilestoneController extends Controller
{
    /**
     * List milestones with escrow funds
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $status = $request->input('status', 'held'); // held, released, all
        
        $query = Milestone::with(['project', 'escrow', 'project.company.user', 'project.client'])
            ->whereHas('escrow');
        
        if ($status !== 'all') {
            $query->whereHas('escrow', function ($q) use ($status) {
                $q->where('status', $status);
            });
        }
        
        $milestones = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
        
        return $this->paginatedResponse($milestones, 'Milestones with escrow retrieved successfully.');
    }
    
    /**
     * Release escrow funds for a milestone
     */
    public function release(Request $request, string $id): JsonResponse
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

        // Use database transaction for atomicity
        return DB::transaction(function () use ($milestone, $validated, $paymentService) {
            // Lock the escrow record to prevent race conditions
            $milestone = Milestone::lockForUpdate()->with(['project', 'escrow', 'project.company'])->findOrFail($milestone->id);
            
            // Re-check escrow status after lock (idempotency check)
            if (!$milestone->escrow) {
                return $this->errorResponse('No escrow found for this milestone', 400);
            }

            if ($milestone->escrow->status !== Escrow::STATUS_HELD) {
                // Check if already released (idempotency)
                if ($milestone->escrow->status === Escrow::STATUS_RELEASED) {
                    Log::info('Escrow already released, returning existing state', [
                        'milestone_id' => $milestone->id,
                        'escrow_id' => $milestone->escrow->id,
                    ]);
                    return $this->successResponse(
                        $milestone->load(['project', 'escrow']),
                        'Escrow was already released.'
                    );
                }
                return $this->errorResponse('Escrow is not in held status', 400);
            }

            // Strong idempotency: Check if transfer already happened by looking for release audit log
            $existingReleaseLog = \App\Models\AuditLog::where('entity_type', 'escrow')
                ->where('entity_id', $milestone->escrow->id)
                ->where('action', 'escrow.released')
                ->whereNotNull('metadata->company_transfer_reference')
                ->first();

            if ($existingReleaseLog) {
                Log::info('Escrow release already processed, returning existing state', [
                    'milestone_id' => $milestone->id,
                    'escrow_id' => $milestone->escrow->id,
                    'transfer_reference' => $existingReleaseLog->metadata['company_transfer_reference'] ?? null,
                ]);
                return $this->successResponse(
                    $milestone->load(['project', 'escrow']),
                    'Escrow was already released.'
                );
            }

            // Calculate amounts
            $totalAmount = $milestone->escrow->amount;
            $platformFee = $milestone->escrow->platform_fee ?? 0;
            $releaseAmount = $milestone->escrow->net_amount ?? ($totalAmount - $platformFee);
            
            try {
                // Step 1: Release net amount to company account
                $releaseData = $paymentService->releaseFunds(
                    reference: $milestone->escrow->payment_reference,
                    recipientAccount: $validated['recipient_account'],
                    amount: $releaseAmount
                );

            // Step 2: Transfer platform fee to admin account (if platform fee exists and admin has account)
            $platformFeeTransferData = null;
            $platformFeeTransferStatus = 'pending';
            if ($platformFee > 0) {
                $adminUser = \App\Models\User::where('role', 'admin')->first();
                if ($adminUser) {
                    $adminAccount = \App\Models\PaymentAccount::getDefaultForUser($adminUser->id);
                    if ($adminAccount) {
                        try {
                            $platformFeeTransferData = $paymentService->transferFromBalance(
                                amount: $platformFee,
                                recipientAccount: [
                                    'name' => $adminAccount->account_name,
                                    'account_number' => $adminAccount->account_number,
                                    'bank_code' => $adminAccount->bank_code,
                                ]
                            );
                            
                            $platformFeeTransferStatus = 'transferred';
                            
                            // Log platform fee transfer
                            app(AuditLogService::class)->log('platform.fee.transferred', 'escrow', $milestone->escrow->id, [
                                'escrow_id' => $milestone->escrow->id,
                                'platform_fee' => $platformFee,
                                'transfer_reference' => $platformFeeTransferData['transfer_reference'] ?? null,
                                'admin_account_id' => $adminAccount->id,
                                'status' => 'transferred',
                            ]);
                        } catch (\Exception $e) {
                            $platformFeeTransferStatus = 'failed';
                            // Log error but don't fail the release
                            \Illuminate\Support\Facades\Log::error('Failed to transfer platform fee to admin', [
                                'escrow_id' => $milestone->escrow->id,
                                'platform_fee' => $platformFee,
                                'error' => $e->getMessage(),
                            ]);
                            
                            app(AuditLogService::class)->log('platform.fee.transfer.failed', 'escrow', $milestone->escrow->id, [
                                'escrow_id' => $milestone->escrow->id,
                                'platform_fee' => $platformFee,
                                'error' => $e->getMessage(),
                                'status' => 'failed',
                                'money_location' => 'paystack_balance',
                            ]);
                        }
                    } else {
                        $platformFeeTransferStatus = 'no_account';
                        app(AuditLogService::class)->log('platform.fee.transfer.pending', 'escrow', $milestone->escrow->id, [
                            'escrow_id' => $milestone->escrow->id,
                            'platform_fee' => $platformFee,
                            'status' => 'pending',
                            'reason' => 'admin_no_payment_account',
                            'money_location' => 'paystack_balance',
                            'message' => 'Admin has not connected a payment account. Platform fee remains in Paystack balance until account is added.',
                        ]);
                        
                        \Illuminate\Support\Facades\Log::warning('Platform fee cannot be transferred - admin has no payment account', [
                            'escrow_id' => $milestone->escrow->id,
                            'admin_user_id' => $adminUser->id,
                            'platform_fee' => $platformFee,
                            'money_location' => 'paystack_balance',
                        ]);
                    }
                } else {
                    $platformFeeTransferStatus = 'no_account';
                    \Illuminate\Support\Facades\Log::warning('Platform fee cannot be transferred - no admin user found', [
                        'escrow_id' => $milestone->escrow->id,
                        'platform_fee' => $platformFee,
                        'money_location' => 'paystack_balance',
                    ]);
                }
            }

            // Update escrow and milestone status
            $milestone->escrow->update([
                'status' => Escrow::STATUS_RELEASED,
            ]);

            $milestone->update([
                'status' => Milestone::STATUS_RELEASED,
            ]);

            // Check if all milestones are released, then mark project as completed
            $project = $milestone->project;
            $allMilestonesReleased = $project->milestones()
                ->whereNotIn('status', [Milestone::STATUS_RELEASED])
                ->doesntExist();

            if ($allMilestonesReleased && $project->status !== Project::STATUS_COMPLETED) {
                $project->update(['status' => Project::STATUS_COMPLETED]);
                
                // Log audit action
                app(AuditLogService::class)->logProjectAction('completed', $project->id, [
                    'completed_by' => auth()->id(),
                    'reason' => 'All milestones released',
                    'auto_completed' => true,
                ]);
            }

                // Log audit action - show correct amounts and transfer references
                app(AuditLogService::class)->logEscrowAction('released', $milestone->escrow->id, [
                    'released_by' => auth()->id(),
                    'total_amount' => $totalAmount,
                    'platform_fee' => $platformFee,
                    'net_amount_to_company' => $releaseAmount,
                    'company_transfer_reference' => $releaseData['transfer_reference'] ?? null,
                    'company_transfer_status' => 'transferred', // Escrow release always transfers to company
                    'platform_fee_transfer_reference' => $platformFeeTransferData['transfer_reference'] ?? null,
                    'platform_fee_transfer_status' => $platformFeeTransferStatus ?? 'pending',
                    'money_location' => ($platformFeeTransferStatus === 'transferred') 
                        ? 'transferred' 
                        : 'paystack_balance',
                    'admin_override' => $validated['override'] ?? false,
                ]);

                // Refresh to get latest data
                $milestone->refresh();

                return $this->successResponse(
                    $milestone->load(['project', 'escrow']),
                    'Escrow released successfully'
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to release escrow', [
                    'milestone_id' => $milestone->id,
                    'error' => $e->getMessage(),
                ]);

                // Transaction will rollback automatically
                throw $e; // Re-throw to trigger rollback
            }
        }, 5); // Retry up to 5 times on deadlock
    }
}
