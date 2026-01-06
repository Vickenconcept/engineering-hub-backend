<?php

namespace App\Services\Payment;

use App\Models\Escrow;
use Illuminate\Support\Facades\Log;

/**
 * Base Payment Service
 * 
 * This is a placeholder implementation.
 * You should create specific implementations like StripePaymentService or PaystackPaymentService
 * that implement PaymentServiceInterface
 */
abstract class PaymentService implements PaymentServiceInterface
{
    protected string $provider;

    /**
     * Log payment action for audit
     */
    protected function logPaymentAction(string $action, array $data): void
    {
        Log::channel('payment')->info("Payment {$action}", [
            'provider' => $this->provider,
            ...$data,
        ]);
    }

    /**
     * Validate amount
     */
    protected function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }
    }
}

