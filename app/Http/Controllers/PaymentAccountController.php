<?php

namespace App\Http\Controllers;

use App\Models\PaymentAccount;
use App\Models\Escrow;
use App\Models\EscrowHoldReference;
use App\Models\Milestone;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentAccountController extends Controller
{
    /**
     * List all payment accounts for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $accounts = PaymentAccount::where('user_id', $user->id)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse($accounts, 'Payment accounts retrieved successfully.');
    }

    /**
     * Store a new payment account
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:20'],
            'bank_code' => ['required', 'string', 'max:10'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_type' => ['nullable', 'string', 'in:nuban,mobile_money'],
            'currency' => ['nullable', 'string', 'max:3'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        // If this is set as default, unset other defaults
        if ($validated['is_default'] ?? false) {
            PaymentAccount::where('user_id', $user->id)
                ->update(['is_default' => false]);
        } else {
            // If this is the first account, make it default
            $hasAccounts = PaymentAccount::where('user_id', $user->id)->exists();
            if (!$hasAccounts) {
                $validated['is_default'] = true;
            }
        }

        $account = PaymentAccount::create([
            'user_id' => $user->id,
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'],
            'bank_code' => $validated['bank_code'],
            'bank_name' => $validated['bank_name'] ?? null,
            'account_type' => $validated['account_type'] ?? 'nuban',
            'currency' => $validated['currency'] ?? 'NGN',
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return $this->successResponse($account, 'Payment account created successfully.');
    }

    /**
     * Update a payment account
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'account_name' => ['sometimes', 'string', 'max:255'],
            'account_number' => ['sometimes', 'string', 'max:20'],
            'bank_code' => ['sometimes', 'string', 'max:10'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        
        $account = PaymentAccount::where('user_id', $user->id)
            ->findOrFail($id);

        // If setting as default, unset other defaults
        if (isset($validated['is_default']) && $validated['is_default']) {
            PaymentAccount::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $account->update($validated);

        return $this->successResponse($account, 'Payment account updated successfully.');
    }

    /**
     * Delete a payment account
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $account = PaymentAccount::where('user_id', $user->id)
            ->findOrFail($id);

        // Don't allow deleting the default account if it's the only one
        $totalAccounts = PaymentAccount::where('user_id', $user->id)->count();
        if ($account->is_default && $totalAccounts > 1) {
            return $this->errorResponse('Cannot delete default account. Set another account as default first.', 400);
        }

        $account->delete();

        return $this->successResponse(null, 'Payment account deleted successfully.');
    }

    /**
     * Set an account as default
     */
    public function setDefault(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $account = PaymentAccount::where('user_id', $user->id)
            ->findOrFail($id);

        // Unset other defaults
        PaymentAccount::where('user_id', $user->id)
            ->where('id', '!=', $id)
            ->update(['is_default' => false]);

        $account->update(['is_default' => true]);

        return $this->successResponse($account, 'Default payment account updated successfully.');
    }

    /**
     * Request escrow release (for companies)
     */
    public function requestEscrowRelease(Request $request, string $milestoneId): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'uuid', 'exists:payment_accounts,id'],
        ]);

        $user = $request->user();
        
        // Get company for the user
        $company = $user->company;
        if (!$company) {
            return $this->errorResponse('User is not associated with a company', 400);
        }
        
        $milestone = Milestone::with(['project', 'escrow'])
            ->whereHas('project', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->findOrFail($milestoneId);

        if (!$milestone->escrow) {
            return $this->errorResponse('No escrow found for this milestone', 400);
        }

        if ($milestone->escrow->status !== Escrow::STATUS_HELD) {
            return $this->errorResponse('Escrow is not in held status', 400);
        }

        if ($milestone->status !== Milestone::STATUS_APPROVED) {
            return $this->errorResponse('Milestone must be approved by client before release', 400);
        }

        // Get payment account (use provided one or default)
        $accountId = $validated['account_id'] ?? null;
        if ($accountId) {
            $account = PaymentAccount::where('user_id', $user->id)
                ->findOrFail($accountId);
        } else {
            $account = PaymentAccount::getDefaultForUser($user->id);
            if (!$account) {
                return $this->errorResponse('No default payment account found. Please add a payment account first.', 400);
            }
        }

        $paymentService = app(\App\Services\Payment\PaymentServiceInterface::class);

        // Use database transaction for atomicity
        return DB::transaction(function () use ($milestone, $account, $paymentService, $user) {
            // Lock the milestone/escrow record to prevent race conditions
            $milestone = Milestone::lockForUpdate()->with(['project', 'escrow'])->findOrFail($milestone->id);
            
            // Re-check escrow status after lock (idempotency check)
            if (!$milestone->escrow) {
                return $this->errorResponse('No escrow found for this milestone', 400);
            }

            if ($milestone->escrow->status !== Escrow::STATUS_HELD) {
                // Check if already released (idempotency)
                if ($milestone->escrow->status === Escrow::STATUS_RELEASED) {
                    Log::info('Escrow already released by company, returning existing state', [
                        'milestone_id' => $milestone->id,
                        'escrow_id' => $milestone->escrow->id,
                        'user_id' => $user->id,
                    ]);
                    return $this->successResponse(
                        [
                            'milestone' => $milestone->load(['project', 'escrow']),
                            'account' => $account,
                        ],
                        'Escrow was already released.'
                    );
                }
                return $this->errorResponse('Escrow is not in held status', 400);
            }

            // Strong idempotency: Check if transfer already happened by looking for release audit log
            $existingReleaseLog = \App\Models\AuditLog::where('entity_type', 'escrow')
                ->where('entity_id', $milestone->escrow->id)
                ->where('action', 'escrow.released')
                ->where('metadata->account_id', $account->id)
                ->whereNotNull('metadata->company_transfer_reference')
                ->first();

            if ($existingReleaseLog) {
                Log::info('Escrow release already processed by this account, returning existing state', [
                    'milestone_id' => $milestone->id,
                    'escrow_id' => $milestone->escrow->id,
                    'account_id' => $account->id,
                    'transfer_reference' => $existingReleaseLog->metadata['company_transfer_reference'] ?? null,
                ]);
                return $this->successResponse(
                    [
                        'milestone' => $milestone->load(['project', 'escrow']),
                        'account' => $account,
                    ],
                    'Escrow was already released with this account.'
                );
            }

            try {
                // Prepare recipient account data
                $recipientAccount = [
                    'name' => $account->account_name,
                    'account_number' => $account->account_number,
                    'bank_code' => $account->bank_code,
                ];

                // Calculate amounts
                $totalAmount = $milestone->escrow->amount;
                $platformFee = $milestone->escrow->platform_fee ?? 0;
                $releaseAmount = $milestone->escrow->net_amount ?? ($totalAmount - $platformFee);

                // Step 1: Release net amount to company account
                $releaseData = $paymentService->releaseFunds(
                    reference: $milestone->escrow->payment_reference,
                    recipientAccount: $recipientAccount,
                    amount: $releaseAmount
                );

            // Step 2: Transfer platform fee to admin account (if platform fee exists)
            $platformFeeTransferData = null;
            $platformFeeTransferStatus = 'pending';
            if ($platformFee > 0) {
                // Get admin user (first admin user)
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

            // Update central hold reference with release transfer ref
            EscrowHoldReference::where('escrow_id', $milestone->escrow->id)->update([
                'status' => EscrowHoldReference::STATUS_RELEASED,
                'paystack_transfer_reference' => $releaseData['transfer_reference'] ?? null,
            ]);

            $milestone->update([
                'status' => Milestone::STATUS_RELEASED,
            ]);

            // Update account with recipient code if provided
            if (isset($releaseData['recipient']['recipient_code'])) {
                $account->update([
                    'recipient_code' => $releaseData['recipient']['recipient_code'],
                    'is_verified' => true,
                ]);
            }

            // Log audit action - show correct amounts and transfer references
            app(AuditLogService::class)->logEscrowAction('released', $milestone->escrow->id, [
                'released_by' => $user->id,
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
                'account_id' => $account->id,
            ]);

                return $this->successResponse(
                    [
                        'milestone' => $milestone->load(['project', 'escrow']),
                        'transfer' => $releaseData,
                        'account' => $account,
                    ],
                    'Escrow funds released successfully.'
                );
            } catch (\Exception $e) {
                Log::error('Escrow release failed', [
                    'milestone_id' => $milestone->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                // Transaction will rollback automatically
                throw $e; // Re-throw to trigger rollback
            }
        }, 5); // Retry up to 5 times on deadlock
    }

    /**
     * Get payment accounts for a specific user (admin only)
     */
    public function getUserAccounts(Request $request, string $userId): JsonResponse
    {
        // Only admins can access other users' accounts
        if (!$request->user()->isAdmin()) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $accounts = PaymentAccount::where('user_id', $userId)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse($accounts, 'Payment accounts retrieved successfully.');
    }

    /**
     * Get list of banks from Paystack
     */
    public function getBanks(Request $request): JsonResponse
    {
        try {
            $paymentService = app(\App\Services\Payment\PaymentServiceInterface::class);
            
            // Check if payment service has getBanks method
            if (!method_exists($paymentService, 'getBanks')) {
                return $this->errorResponse('Bank list not available', 501);
            }

            $banks = $paymentService->getBanks();
            
            return $this->successResponse($banks, 'Banks retrieved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch banks: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify/resolve bank account number
     */
    public function verifyAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_number' => ['required', 'string'],
            'bank_code' => ['required', 'string'],
        ]);

        try {
            $paymentService = app(\App\Services\Payment\PaymentServiceInterface::class);
            
            // Check if payment service has resolveAccount method
            if (!method_exists($paymentService, 'resolveAccount')) {
                return $this->errorResponse('Account verification not available', 501);
            }

            $accountData = $paymentService->resolveAccount(
                $validated['account_number'],
                $validated['bank_code']
            );
            
            return $this->successResponse($accountData, 'Account verified successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to verify account: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Request escrow refund (for clients)
     */
    public function requestEscrowRefund(Request $request, string $milestoneId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'account_id' => ['nullable', 'uuid', 'exists:payment_accounts,id'],
        ]);

        $user = $request->user();
        
        $milestone = Milestone::with(['project', 'escrow'])
            ->whereHas('project', function ($query) use ($user) {
                $query->where('client_id', $user->id);
            })
            ->findOrFail($milestoneId);

        if (!$milestone->escrow) {
            return $this->errorResponse('No escrow found for this milestone', 400);
        }

        if ($milestone->escrow->status !== Escrow::STATUS_HELD) {
            return $this->errorResponse('Escrow is not in held status', 400);
        }

        // Get payment account (use provided one or default)
        $accountId = $validated['account_id'] ?? null;
        if ($accountId) {
            $account = PaymentAccount::where('user_id', $user->id)
                ->findOrFail($accountId);
        } else {
            $account = PaymentAccount::getDefaultForUser($user->id);
            if (!$account) {
                return $this->errorResponse('No default payment account found. Please add a payment account first.', 400);
            }
        }

        $paymentService = app(\App\Services\Payment\PaymentServiceInterface::class);

        try {
            // Process refund
            $refundData = $paymentService->refundPayment(
                reference: $milestone->escrow->payment_reference
            );

            // Update escrow status
            $milestone->escrow->update([
                'status' => Escrow::STATUS_REFUNDED,
            ]);

            // Update central hold reference
            EscrowHoldReference::where('escrow_id', $milestone->escrow->id)->update([
                'status' => EscrowHoldReference::STATUS_REFUNDED,
            ]);

            // Log audit action
            app(AuditLogService::class)->logEscrowAction('refunded', $milestone->escrow->id, [
                'refunded_by' => $user->id,
                'amount' => $milestone->escrow->amount,
                'reason' => $validated['reason'],
                'account_id' => $account->id,
                'refund_reference' => $refundData['refund_reference'] ?? null,
            ]);

            return $this->successResponse(
                [
                    'milestone' => $milestone->load(['project', 'escrow']),
                    'refund' => $refundData,
                    'account' => $account,
                ],
                'Escrow refund processed successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Escrow refund failed', [
                'milestone_id' => $milestoneId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to process refund: ' . $e->getMessage(), 500);
        }
    }
}
