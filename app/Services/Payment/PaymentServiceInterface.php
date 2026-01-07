<?php

namespace App\Services\Payment;

/**
 * Payment Service Interface
 * 
 * Abstract interface for payment providers (Stripe, Paystack, etc.)
 */
interface PaymentServiceInterface
{
    /**
     * Initialize a payment transaction
     * 
     * @param float $amount Amount to charge
     * @param string $currency Currency code (default: NGN)
     * @param array $metadata Additional metadata
     * @return array Payment initialization data (reference, authorization_url, etc.)
     */
    public function initializePayment(float $amount, string $currency = 'NGN', array $metadata = []): array;

    /**
     * Verify a payment transaction
     * 
     * @param string $reference Payment reference
     * @return array Payment verification data (status, amount, etc.)
     */
    public function verifyPayment(string $reference): array;

    /**
     * Release funds from escrow (transfer to company)
     * 
     * @param string $reference Escrow payment reference
     * @param array $recipientAccount Account data (name, account_number, bank_code)
     * @param float|null $amount Optional amount to release (defaults to full payment amount)
     * @return array Transfer result data
     */
    public function releaseFunds(string $reference, array $recipientAccount, ?float $amount = null): array;

    /**
     * Refund a payment
     * 
     * @param string $reference Payment reference
     * @return array Refund result data
     */
    public function refundPayment(string $reference): array;

    /**
     * Transfer funds from platform balance to a recipient account
     * Used for transferring platform fees to admin account
     * 
     * @param float $amount Amount to transfer
     * @param array $recipientAccount Account data (name, account_number, bank_code)
     * @return array Transfer result data
     */
    public function transferFromBalance(float $amount, array $recipientAccount): array;
}

