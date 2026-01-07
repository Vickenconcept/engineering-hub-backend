<?php

namespace App\Http\Controllers;

use App\Models\PaymentAccount;
use App\Models\Escrow;
use App\Models\Milestone;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        try {
            // Prepare recipient account data
            $recipientAccount = [
                'name' => $account->account_name,
                'account_number' => $account->account_number,
                'bank_code' => $account->bank_code,
            ];

            // Release funds
            $releaseData = $paymentService->releaseFunds(
                reference: $milestone->escrow->payment_reference,
                recipientAccount: $recipientAccount
            );

            // Update escrow and milestone status
            $milestone->escrow->update([
                'status' => Escrow::STATUS_RELEASED,
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

            // Log audit action
            app(AuditLogService::class)->logEscrowAction('released', $milestone->escrow->id, [
                'released_by' => $user->id,
                'amount' => $milestone->escrow->amount,
                'account_id' => $account->id,
                'transfer_reference' => $releaseData['transfer_reference'] ?? null,
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
                'milestone_id' => $milestoneId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to release funds: ' . $e->getMessage(), 500);
        }
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
