<?php

namespace App\Http\Controllers;

use App\Services\Payment\PaymentServiceInterface;
use App\Models\Consultation;
use App\Models\Milestone;
use App\Models\Escrow;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            return redirect("{$frontendUrl}/payment/callback?error=no_reference");
        }

        $paymentService = app(PaymentServiceInterface::class);

        try {
            // Verify payment with Paystack
            $paymentData = $paymentService->verifyPayment($reference);

            if ($paymentData['status'] === 'success') {
                // Get metadata to determine what was paid for
                $metadata = $paymentData['metadata'] ?? [];
                $type = $metadata['type'] ?? null;

                if ($type === 'consultation') {
                    $this->handleConsultationPayment($paymentData);
                } elseif ($type === 'milestone_escrow') {
                    $this->handleMilestoneEscrowPayment($paymentData);
                }

                // Redirect to frontend with success
                return redirect("{$frontendUrl}/payment/callback?reference={$reference}&status=success");
            } else {
                // Payment failed
                Log::warning('Payment verification failed', [
                    'reference' => $reference,
                    'status' => $paymentData['status'] ?? 'unknown',
                ]);
                return redirect("{$frontendUrl}/payment/callback?reference={$reference}&status=failed");
            }
        } catch (\Exception $e) {
            Log::error('Payment callback error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Redirect to frontend with error
            return redirect("{$frontendUrl}/payment/callback?reference={$reference}&status=error&message=" . urlencode($e->getMessage()));
        }
    }

    /**
     * Handle consultation payment verification
     */
    protected function handleConsultationPayment(array $paymentData): void
    {
        $metadata = $paymentData['metadata'] ?? [];
        $consultationId = $metadata['consultation_id'] ?? null;

        if (!$consultationId) {
            Log::warning('Consultation payment without consultation_id in metadata', [
                'metadata' => $metadata,
            ]);
            return;
        }

        $consultation = Consultation::find($consultationId);
        if (!$consultation) {
            Log::warning('Consultation not found for payment', [
                'consultation_id' => $consultationId,
            ]);
            return;
        }

        // Only update if not already paid
        if (!$consultation->isPaid()) {
            $consultation->update([
                'payment_status' => Consultation::PAYMENT_STATUS_PAID,
                'meeting_link' => 'https://meet.example.com/' . $consultation->id, // TODO: Generate actual meeting link
            ]);

            app(AuditLogService::class)->log('consultation.paid', 'consultation', $consultation->id, [
                'payment_reference' => $paymentData['reference'],
                'amount' => $paymentData['amount'],
            ]);

            Log::info('Consultation payment processed', [
                'consultation_id' => $consultationId,
                'reference' => $paymentData['reference'],
            ]);
        }
    }

    /**
     * Handle milestone escrow payment verification
     */
    protected function handleMilestoneEscrowPayment(array $paymentData): void
    {
        $metadata = $paymentData['metadata'] ?? [];
        $milestoneId = $metadata['milestone_id'] ?? null;

        if (!$milestoneId) {
            Log::warning('Milestone escrow payment without milestone_id in metadata', [
                'metadata' => $metadata,
            ]);
            return;
        }

        $milestone = Milestone::find($milestoneId);
        if (!$milestone) {
            Log::warning('Milestone not found for payment', [
                'milestone_id' => $milestoneId,
            ]);
            return;
        }

        // Check if escrow already exists
        $escrow = Escrow::where('milestone_id', $milestone->id)
            ->where('payment_reference', $paymentData['reference'])
            ->first();

        if ($escrow) {
            Log::info('Escrow already exists for this payment', [
                'milestone_id' => $milestoneId,
                'reference' => $paymentData['reference'],
            ]);
            return;
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

        Log::info('Milestone escrow payment processed', [
            'milestone_id' => $milestoneId,
            'escrow_id' => $escrow->id,
            'reference' => $paymentData['reference'],
        ]);
    }
}

