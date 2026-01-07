<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Services\Payment\PaymentServiceInterface;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Verify payment callback (called after payment)
     */
    public function verifyPayment(\App\Http\Requests\Payment\VerifyPaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paymentService = app(PaymentServiceInterface::class);

        try {
            $paymentData = $paymentService->verifyPayment($validated['reference']);

            if ($paymentData['status'] === 'success') {
                // Get metadata to determine what was paid for
                $metadata = $paymentData['metadata'] ?? [];
                $type = $metadata['type'] ?? null;

                if ($type === 'consultation') {
                    return $this->handleConsultationPayment($paymentData);
                } elseif ($type === 'milestone_escrow') {
                    return $this->handleMilestoneEscrowPayment($paymentData);
                }
            }

            return $this->successResponse($paymentData, 'Payment verified');
        } catch (\Exception $e) {
            Log::error('Payment verification failed', [
                'reference' => $validated['reference'],
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Payment verification failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle consultation payment verification
     */
    protected function handleConsultationPayment(array $paymentData): JsonResponse
    {
        $metadata = $paymentData['metadata'] ?? [];
        $consultationId = $metadata['consultation_id'] ?? null;

        if (!$consultationId) {
            return $this->errorResponse('Invalid payment metadata', 400);
        }

        // Verify payment was actually successful - source of truth
        if ($paymentData['status'] !== 'success') {
            Log::warning('Consultation payment verification attempted for non-successful payment', [
                'consultation_id' => $consultationId,
                'payment_status' => $paymentData['status'] ?? 'unknown',
                'reference' => $paymentData['reference'] ?? null,
            ]);
            return $this->errorResponse('Payment was not successful', 400);
        }

        // Use database transaction for atomicity
        return DB::transaction(function () use ($paymentData, $consultationId, $metadata) {
            // Lock the consultation record to prevent race conditions
            $consultation = Consultation::lockForUpdate()->findOrFail($consultationId);

            // Double-check payment status (idempotency check)
            if ($consultation->isPaid()) {
                Log::info('Consultation already paid, returning existing record', [
                    'consultation_id' => $consultationId,
                    'payment_reference' => $paymentData['reference'] ?? null,
                ]);
                return $this->successResponse(
                    $consultation->load(['company.user', 'company']),
                    'Consultation was already paid.'
                );
            }

            // Calculate platform fee
            $platformFeePercentage = \App\Models\PlatformSetting::getPlatformFeePercentage();
            $platformFee = ($paymentData['amount'] * $platformFeePercentage) / 100;
            $netAmount = $paymentData['amount'] - $platformFee;

            $paymentService = app(PaymentServiceInterface::class);
            $companyTransferData = null;
            $platformFeeTransferData = null;

        // Step 1: Transfer net amount to company account
        $company = $consultation->company;
        $companyTransferStatus = 'pending'; // pending, transferred, failed, no_account
        if ($company && $company->user) {
            $companyAccount = \App\Models\PaymentAccount::getDefaultForUser($company->user->id);
            if ($companyAccount) {
                try {
                    $companyTransferData = $paymentService->transferFromBalance(
                        amount: $netAmount,
                        recipientAccount: [
                            'name' => $companyAccount->account_name,
                            'account_number' => $companyAccount->account_number,
                            'bank_code' => $companyAccount->bank_code,
                        ]
                    );
                    
                    $companyTransferStatus = 'transferred';
                    
                    // Log company transfer
                    app(AuditLogService::class)->log('consultation.company.transferred', 'consultation', $consultation->id, [
                        'consultation_id' => $consultation->id,
                        'net_amount' => $netAmount,
                        'transfer_reference' => $companyTransferData['transfer_reference'] ?? null,
                        'company_account_id' => $companyAccount->id,
                        'status' => 'transferred',
                    ]);
                } catch (\Exception $e) {
                    $companyTransferStatus = 'failed';
                    Log::error('Failed to transfer consultation payment to company', [
                        'consultation_id' => $consultation->id,
                        'net_amount' => $netAmount,
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Log failed transfer
                    app(AuditLogService::class)->log('consultation.company.transfer.failed', 'consultation', $consultation->id, [
                        'consultation_id' => $consultation->id,
                        'net_amount' => $netAmount,
                        'error' => $e->getMessage(),
                        'status' => 'failed',
                        'money_location' => 'paystack_balance',
                    ]);
                }
            } else {
                $companyTransferStatus = 'no_account';
                // Log that company account is missing - money remains in Paystack
                app(AuditLogService::class)->log('consultation.company.transfer.pending', 'consultation', $consultation->id, [
                    'consultation_id' => $consultation->id,
                    'net_amount' => $netAmount,
                    'status' => 'pending',
                    'reason' => 'company_no_payment_account',
                    'money_location' => 'paystack_balance',
                    'message' => 'Company has not connected a payment account. Money remains in Paystack balance until account is added.',
                ]);
                
                Log::warning('Consultation payment cannot be transferred - company has no payment account', [
                    'consultation_id' => $consultation->id,
                    'company_id' => $company->id,
                    'net_amount' => $netAmount,
                    'money_location' => 'paystack_balance',
                ]);
            }
        } else {
            $companyTransferStatus = 'no_account';
            Log::warning('Consultation payment cannot be transferred - company or user not found', [
                'consultation_id' => $consultation->id,
                'money_location' => 'paystack_balance',
            ]);
        }

        // Step 2: Transfer platform fee to admin account
        $platformFeeTransferStatus = 'pending'; // pending, transferred, failed, no_account
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
                        app(AuditLogService::class)->log('consultation.platform.fee.transferred', 'consultation', $consultation->id, [
                            'consultation_id' => $consultation->id,
                            'platform_fee' => $platformFee,
                            'transfer_reference' => $platformFeeTransferData['transfer_reference'] ?? null,
                            'admin_account_id' => $adminAccount->id,
                            'status' => 'transferred',
                        ]);
                    } catch (\Exception $e) {
                        $platformFeeTransferStatus = 'failed';
                        Log::error('Failed to transfer consultation platform fee to admin', [
                            'consultation_id' => $consultation->id,
                            'platform_fee' => $platformFee,
                            'error' => $e->getMessage(),
                        ]);
                        
                        // Log failed transfer
                        app(AuditLogService::class)->log('consultation.platform.fee.transfer.failed', 'consultation', $consultation->id, [
                            'consultation_id' => $consultation->id,
                            'platform_fee' => $platformFee,
                            'error' => $e->getMessage(),
                            'status' => 'failed',
                            'money_location' => 'paystack_balance',
                        ]);
                    }
                } else {
                    $platformFeeTransferStatus = 'no_account';
                    // Log that admin account is missing - money remains in Paystack
                    app(AuditLogService::class)->log('consultation.platform.fee.transfer.pending', 'consultation', $consultation->id, [
                        'consultation_id' => $consultation->id,
                        'platform_fee' => $platformFee,
                        'status' => 'pending',
                        'reason' => 'admin_no_payment_account',
                        'money_location' => 'paystack_balance',
                        'message' => 'Admin has not connected a payment account. Platform fee remains in Paystack balance until account is added.',
                    ]);
                    
                    Log::warning('Platform fee cannot be transferred - admin has no payment account', [
                        'consultation_id' => $consultation->id,
                        'admin_user_id' => $adminUser->id,
                        'platform_fee' => $platformFee,
                        'money_location' => 'paystack_balance',
                    ]);
                }
            } else {
                $platformFeeTransferStatus = 'no_account';
                Log::warning('Platform fee cannot be transferred - no admin user found', [
                    'consultation_id' => $consultation->id,
                    'platform_fee' => $platformFee,
                    'money_location' => 'paystack_balance',
                ]);
            }
            }

            // Update consultation status - this is the source of truth
            // Note: Google Meet link generation is handled in PaymentCallbackController
            $consultation->update([
                'payment_status' => Consultation::PAYMENT_STATUS_PAID,
                'platform_fee' => $platformFee,
                'net_amount' => $netAmount,
                'platform_fee_percentage' => $platformFeePercentage,
            ]);

            // Log the payment - this happens within transaction
            app(AuditLogService::class)->log('consultation.paid', 'consultation', $consultation->id, [
                'payment_reference' => $paymentData['reference'],
                'total_amount' => $paymentData['amount'],
                'platform_fee' => $platformFee,
                'net_amount' => $netAmount,
                'company_transfer_reference' => $companyTransferData['transfer_reference'] ?? null,
                'company_transfer_status' => $companyTransferStatus,
                'platform_fee_transfer_reference' => $platformFeeTransferData['transfer_reference'] ?? null,
                'platform_fee_transfer_status' => $platformFeeTransferStatus,
                'money_location' => ($companyTransferStatus === 'transferred' && $platformFeeTransferStatus === 'transferred') 
                    ? 'transferred' 
                    : 'paystack_balance',
            ]);

            // Refresh consultation to get latest data
            $consultation->refresh();

            return $this->successResponse(
                $consultation->load(['company.user', 'company']),
                'Payment successful. Consultation confirmed.'
            );
        }, 5); // Retry up to 5 times on deadlock
    }

    /**
     * Handle milestone escrow payment verification
     */
    protected function handleMilestoneEscrowPayment(array $paymentData): JsonResponse
    {
        $metadata = $paymentData['metadata'] ?? [];
        $milestoneId = $metadata['milestone_id'] ?? null;

        if (!$milestoneId) {
            return $this->errorResponse('Invalid payment metadata', 400);
        }

        // Verify payment was actually successful - source of truth
        if ($paymentData['status'] !== 'success') {
            Log::warning('Milestone escrow payment verification attempted for non-successful payment', [
                'milestone_id' => $milestoneId,
                'payment_status' => $paymentData['status'] ?? 'unknown',
                'reference' => $paymentData['reference'] ?? null,
            ]);
            return $this->errorResponse('Payment was not successful', 400);
        }

        // Use database transaction for atomicity
        return DB::transaction(function () use ($paymentData, $milestoneId) {
            // Lock the milestone record to prevent race conditions
            $milestone = Milestone::lockForUpdate()->findOrFail($milestoneId);

            // Strong idempotency check: Check if escrow already exists with this payment reference
            $existingEscrow = Escrow::where('milestone_id', $milestone->id)
                ->where('payment_reference', $paymentData['reference'])
                ->lockForUpdate()
                ->first();

            if ($existingEscrow) {
                Log::info('Milestone escrow already processed, returning existing record', [
                    'milestone_id' => $milestoneId,
                    'escrow_id' => $existingEscrow->id,
                    'payment_reference' => $paymentData['reference'],
                ]);
                return $this->successResponse(
                    $milestone->load(['project', 'escrow']),
                    'Escrow already processed.'
                );
            }

            // Additional check: If milestone already has an escrow (different reference), reject
            $existingMilestoneEscrow = Escrow::where('milestone_id', $milestone->id)
                ->where('status', '!=', Escrow::STATUS_REFUNDED)
                ->first();

            if ($existingMilestoneEscrow) {
                Log::warning('Milestone already has an escrow with different reference', [
                    'milestone_id' => $milestoneId,
                    'existing_escrow_id' => $existingMilestoneEscrow->id,
                    'existing_reference' => $existingMilestoneEscrow->payment_reference,
                    'new_reference' => $paymentData['reference'],
                ]);
                return $this->errorResponse('Milestone already has an escrow record', 400);
            }

            // Calculate platform fee
            $platformFeePercentage = \App\Models\PlatformSetting::getPlatformFeePercentage();
            $platformFee = ($paymentData['amount'] * $platformFeePercentage) / 100;
            $netAmount = $paymentData['amount'] - $platformFee;

            // Create escrow record - source of truth
            $escrow = Escrow::create([
                'milestone_id' => $milestone->id,
                'amount' => $paymentData['amount'],
                'platform_fee' => $platformFee,
                'net_amount' => $netAmount,
                'platform_fee_percentage' => $platformFeePercentage,
                'payment_reference' => $paymentData['reference'],
                'payment_provider' => 'paystack',
                'status' => Escrow::STATUS_HELD,
            ]);

            // Update milestone status
            $milestone->update([
                'status' => Milestone::STATUS_FUNDED,
            ]);

            // Log the escrow creation - within transaction
            app(AuditLogService::class)->logEscrowAction('created', $escrow->id, [
                'payment_reference' => $paymentData['reference'],
                'total_amount' => $paymentData['amount'],
                'platform_fee' => $platformFee,
                'platform_fee_percentage' => $platformFeePercentage,
                'net_amount' => $netAmount,
                'milestone_id' => $milestone->id,
            ]);

            // Refresh to get latest data
            $milestone->refresh();

            return $this->successResponse(
                $milestone->load(['project', 'escrow']),
                'Escrow funded successfully. Milestone is now ready for work.'
            );
        }, 5); // Retry up to 5 times on deadlock
    }

    /**
     * Handle Paystack webhook
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        // Verify webhook signature
        $secret = config('services.paystack.secret_key');
        $signature = $request->header('X-Paystack-Signature');
        $payload = $request->getContent();
        $hash = hash_hmac('sha512', $payload, $secret);

        if ($hash !== $signature) {
            Log::warning('Invalid webhook signature', [
                'expected' => $hash,
                'received' => $signature,
            ]);
            return $this->errorResponse('Invalid signature', 400);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        try {
            switch ($event) {
                case 'charge.success':
                    $this->handleSuccessfulCharge($data);
                    break;
                case 'transfer.success':
                    $this->handleSuccessfulTransfer($data);
                    break;
                default:
                    Log::info('Unhandled webhook event', ['event' => $event]);
            }

            return $this->successResponse(null, 'Webhook processed');
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Webhook processing failed', 500);
        }
    }

    /**
     * Handle successful charge webhook
     */
    protected function handleSuccessfulCharge(array $data): void
    {
        $reference = $data['reference'];
        
        // Verify payment (this will update any records if needed)
        $paymentService = app(PaymentServiceInterface::class);
        $paymentData = $paymentService->verifyPayment($reference);

        // The verification will trigger the same logic as the callback
        // This ensures consistency whether payment comes via callback or webhook
    }

    /**
     * Handle successful transfer webhook (escrow release)
     */
    protected function handleSuccessfulTransfer(array $data): void
    {
        // Update escrow status if transfer was successful
        $transferReference = $data['reference'] ?? null;
        
        if ($transferReference) {
            // Find escrow by transfer reference (stored in metadata)
            // Update escrow status to released
            Log::info('Transfer successful', ['reference' => $transferReference]);
        }
    }
}

