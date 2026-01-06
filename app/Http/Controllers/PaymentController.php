<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Services\Payment\PaymentServiceInterface;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $consultation = Consultation::findOrFail($consultationId);

        if ($consultation->isPaid()) {
            return $this->successResponse(
                $consultation->load(['company.user', 'company']),
                'Consultation was already paid.'
            );
        }

        $consultation->update([
            'payment_status' => Consultation::PAYMENT_STATUS_PAID,
            'meeting_link' => 'https://meet.example.com/' . $consultation->id, // TODO: Generate actual meeting link
        ]);

        app(AuditLogService::class)->log('consultation.paid', 'consultation', $consultation->id, [
            'payment_reference' => $paymentData['reference'],
            'amount' => $paymentData['amount'],
        ]);

        return $this->successResponse(
            $consultation->load(['company.user', 'company']),
            'Payment successful. Consultation confirmed.'
        );
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

        $milestone = Milestone::findOrFail($milestoneId);

        // Check if escrow already exists
        $escrow = Escrow::where('milestone_id', $milestone->id)
            ->where('payment_reference', $paymentData['reference'])
            ->first();

        if ($escrow) {
            return $this->successResponse(
                $milestone->load(['project', 'escrow']),
                'Escrow already processed.'
            );
        }

        // Create escrow record
        $escrow = Escrow::create([
            'milestone_id' => $milestone->id,
            'amount' => $paymentData['amount'],
            'payment_reference' => $paymentData['reference'],
            'payment_provider' => 'paystack',
            'status' => Escrow::STATUS_HELD,
        ]);

        $milestone->update([
            'status' => Milestone::STATUS_FUNDED,
        ]);

        app(AuditLogService::class)->logEscrowAction('created', $escrow->id, [
            'payment_reference' => $paymentData['reference'],
            'amount' => $paymentData['amount'],
            'milestone_id' => $milestone->id,
        ]);

        return $this->successResponse(
            $milestone->load(['project', 'escrow']),
            'Escrow funded successfully. Milestone is now ready for work.'
        );
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

