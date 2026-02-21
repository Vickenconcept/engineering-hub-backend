<?php

namespace App\Http\Controllers;

use App\Services\Payment\PaymentServiceInterface;
use App\Models\Consultation;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Models\EscrowHoldReference;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Notifications\ConsultationPaidNotification;
use App\Services\AuditLogService;
use App\Services\VideoMeeting\GoogleMeetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;

class PaymentCallbackController extends Controller
{
    /**
     * Handle payment callback from Paystack
     * This is called when Paystack redirects the user back after payment
     */
    public function handle(Request $request)
    {
        $reference = $request->query('reference') ?? $request->query('trxref');
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        
        if (!$reference) {
            Log::warning('Payment callback received without reference', [
                'query' => $request->query(),
            ]);
            return Redirect::to("{$frontendUrl}/payment/callback?error=no_reference");
        }

        $paymentService = app(PaymentServiceInterface::class);

        try {
            // Verify payment with Paystack
            $paymentData = $paymentService->verifyPayment($reference);

            if ($paymentData['status'] === 'success') {
                // Get metadata to determine what was paid for
                $metadata = $paymentData['metadata'] ?? [];
                $type = $metadata['type'] ?? null;

                $consultationId = null;
                $milestoneId = null;
                $projectId = null;

                if ($type === 'consultation') {
                    $consultationId = $this->handleConsultationPayment($paymentData);
                } elseif ($type === 'milestone_escrow') {
                    $result = $this->handleMilestoneEscrowPayment($paymentData);
                    $milestoneId = $result['milestone_id'] ?? null;
                    $projectId = $result['project_id'] ?? null;
                }

                // Build redirect URL with relevant IDs
                $redirectUrl = "{$frontendUrl}/payment/callback?reference={$reference}&status=success";
                if ($consultationId) {
                    $redirectUrl .= "&consultation_id={$consultationId}";
                }
                if ($milestoneId) {
                    $redirectUrl .= "&milestone_id={$milestoneId}";
                }
                if ($projectId) {
                    $redirectUrl .= "&project_id={$projectId}";
                }

                // Redirect to frontend with success
                return Redirect::to($redirectUrl);
            } else {
                // Payment failed
                Log::warning('Payment verification failed', [
                    'reference' => $reference,
                    'status' => $paymentData['status'] ?? 'unknown',
                ]);
                return Redirect::to("{$frontendUrl}/payment/callback?reference={$reference}&status=failed");
            }
        } catch (\Exception $e) {
            Log::error('Payment callback error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Redirect to frontend with error
            return Redirect::to("{$frontendUrl}/payment/callback?reference={$reference}&status=error&message=" . urlencode($e->getMessage()));
        }
    }

    /**
     * Handle consultation payment verification
     * @return string|null Returns consultation_id if successful
     */
    protected function handleConsultationPayment(array $paymentData): ?string
    {
        $metadata = $paymentData['metadata'] ?? [];
        $consultationId = $metadata['consultation_id'] ?? null;

        if (!$consultationId) {
            Log::warning('Consultation payment without consultation_id in metadata', [
                'metadata' => $metadata,
            ]);
            return null;
        }

        // Verify payment was actually successful - source of truth
        if ($paymentData['status'] !== 'success') {
            Log::warning('Consultation payment callback received for non-successful payment', [
                'consultation_id' => $consultationId,
                'payment_status' => $paymentData['status'] ?? 'unknown',
                'reference' => $paymentData['reference'] ?? null,
            ]);
            return null;
        }

        // Use database transaction for atomicity
        DB::transaction(function () use ($paymentData, $consultationId) {
            // Lock the consultation record to prevent race conditions
            // Eager load relationships needed for meeting generation
            $consultation = Consultation::with(['client', 'company.user'])
                ->lockForUpdate()
                ->find($consultationId);
            
            if (!$consultation) {
                Log::warning('Consultation not found for payment', [
                    'consultation_id' => $consultationId,
                ]);
                return;
            }

            // Double-check payment status (idempotency check)
            if ($consultation->isPaid()) {
                Log::info('Consultation already paid in callback, skipping', [
                    'consultation_id' => $consultation->id,
                    'payment_reference' => $paymentData['reference'] ?? null,
                ]);
                return;
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
            $companyTransferStatus = 'pending';
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

            // Generate Google Meet link
            $meetingLink = null;
            $calendarEventId = null;
            
            Log::info('Attempting to generate Google Meet link', [
                'consultation_id' => $consultation->id,
            ]);
            
            try {
                // Get client and company emails (relationships already loaded)
                $client = $consultation->client;
                $company = $consultation->company;
                
                Log::info('Consultation relationships loaded', [
                    'consultation_id' => $consultation->id,
                    'has_client' => !!$client,
                    'has_company' => !!$company,
                    'has_company_user' => !!($company && $company->user),
                    'client_email' => $client?->email,
                    'company_user_email' => $company?->user?->email,
                ]);
                
                if ($client && $company && $company->user) {
                    Log::info('Initializing GoogleMeetService', [
                        'consultation_id' => $consultation->id,
                    ]);
                    
                    $googleMeetService = app(GoogleMeetService::class);
                    
                    Log::info('Calling createMeeting', [
                        'consultation_id' => $consultation->id,
                        'scheduled_at' => $consultation->scheduled_at->toIso8601String(),
                        'duration_minutes' => $consultation->duration_minutes ?? 30,
                        'client_email' => $client->email,
                        'company_email' => $company->user->email,
                    ]);
                    
                    $meetingData = $googleMeetService->createMeeting(
                        consultationId: $consultation->id,
                        startTime: $consultation->scheduled_at,
                        durationMinutes: $consultation->duration_minutes ?? 30,
                        clientEmail: $client->email,
                        companyEmail: $company->user->email,
                        title: "Consultation: {$company->company_name}",
                        description: "Consultation meeting between {$client->name} and {$company->company_name}"
                    );
                    
                    $meetingLink = $meetingData['meeting_link'];
                    $calendarEventId = $meetingData['calendar_event_id'];
                    
                    Log::info('Google Meet link generated successfully', [
                        'consultation_id' => $consultation->id,
                        'meeting_link' => $meetingLink,
                        'calendar_event_id' => $calendarEventId,
                    ]);
                } else {
                    Log::warning('Cannot generate meeting link - missing client or company email', [
                        'consultation_id' => $consultation->id,
                        'has_client' => !!$client,
                        'has_company' => !!$company,
                        'has_company_user' => !!($company && $company->user),
                        'client_id' => $consultation->client_id,
                        'company_id' => $consultation->company_id,
                    ]);
                }
            } catch (\Exception $e) {
                // Don't fail the payment if meeting link generation fails
                Log::error('Failed to generate Google Meet link for consultation', [
                    'consultation_id' => $consultation->id,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue without meeting link - can be generated later
            }

            // Update consultation status - this is the source of truth
            $consultation->update([
                'payment_status' => Consultation::PAYMENT_STATUS_PAID,
                'platform_fee' => $platformFee,
                'net_amount' => $netAmount,
                'platform_fee_percentage' => $platformFeePercentage,
                'meeting_link' => $meetingLink,
                'calendar_event_id' => $calendarEventId,
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

            Log::info('Consultation payment processed', [
                'consultation_id' => $consultation->id,
                'reference' => $paymentData['reference'],
            ]);

            // Send notifications
            $consultation->load(['client', 'company.user']);
            $consultation->client->notify(new ConsultationPaidNotification($consultation));
            if ($consultation->company->user) {
                $consultation->company->user->notify(new ConsultationPaidNotification($consultation));
            }
        }, 5); // Retry up to 5 times on deadlock

        return (string) $consultationId;
    }

    /**
     * Handle milestone escrow payment verification
     * @return array Returns ['milestone_id' => string, 'project_id' => string] if successful
     */
    protected function handleMilestoneEscrowPayment(array $paymentData): array
    {
        $metadata = $paymentData['metadata'] ?? [];
        $milestoneId = $metadata['milestone_id'] ?? null;

        if (!$milestoneId) {
            Log::warning('Milestone escrow payment without milestone_id in metadata', [
                'metadata' => $metadata,
            ]);
            return [];
        }

        // Verify payment was actually successful - source of truth
        if ($paymentData['status'] !== 'success') {
            Log::warning('Milestone escrow payment callback received for non-successful payment', [
                'milestone_id' => $milestoneId,
                'payment_status' => $paymentData['status'] ?? 'unknown',
                'reference' => $paymentData['reference'] ?? null,
            ]);
            return [];
        }

        // Use database transaction for atomicity
        return DB::transaction(function () use ($paymentData, $milestoneId) {
            // Lock the milestone record to prevent race conditions
            $milestone = Milestone::lockForUpdate()->with('project')->find($milestoneId);
            
            if (!$milestone) {
                Log::warning('Milestone not found for payment', [
                    'milestone_id' => $milestoneId,
                ]);
                return [];
            }

            // Strong idempotency check: Check if escrow already exists with this payment reference
            $existingEscrow = Escrow::where('milestone_id', $milestone->id)
                ->where('payment_reference', $paymentData['reference'])
                ->lockForUpdate()
                ->first();

            if ($existingEscrow) {
                Log::info('Escrow already exists for this payment', [
                    'milestone_id' => $milestoneId,
                    'escrow_id' => $existingEscrow->id,
                    'reference' => $paymentData['reference'],
                ]);
                return [
                    'milestone_id' => $milestone->id,
                    'project_id' => $milestone->project_id,
                ];
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
                return [
                    'milestone_id' => $milestone->id,
                    'project_id' => $milestone->project_id,
                ];
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

            // Central hold reference: one ID to look up client, company, project, milestone
            $escrow->setRelation('milestone', $milestone);
            EscrowHoldReference::createForEscrow($escrow, $paymentData['reference']);

            Log::info('Milestone escrow payment processed', [
                'milestone_id' => $milestoneId,
                'escrow_id' => $escrow->id,
                'reference' => $paymentData['reference'],
            ]);

            return [
                'milestone_id' => $milestone->id,
                'project_id' => $milestone->project_id,
            ];
        }, 5); // Retry up to 5 times on deadlock
    }
}

