<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackPaymentService extends PaymentService implements PaymentServiceInterface
{
    protected string $provider = 'paystack';

    protected string $secretKey;
    protected string $publicKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->publicKey = config('services.paystack.public_key');
        $this->baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');
        
        if (empty($this->secretKey)) {
            throw new \RuntimeException('Paystack secret key is not configured');
        }
    }

    /**
     * Initialize a payment transaction
     */
    public function initializePayment(float $amount, string $currency = 'NGN', array $metadata = []): array
    {
        $this->validateAmount($amount);

        // Convert amount to kobo (Paystack uses kobo for NGN)
        $amountInKobo = $this->convertToKobo($amount, $currency);
        
        // Generate unique reference
        $reference = $this->generateReference();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/transaction/initialize", [
                'email' => $metadata['email'] ?? auth()->user()->email ?? 'customer@example.com',
                'amount' => $amountInKobo,
                'currency' => $currency,
                'reference' => $reference,
                'callback_url' => $metadata['callback_url'] ?? config('app.url') . '/api/payments/callback',
                'metadata' => array_merge([
                    'custom_fields' => [],
                ], $metadata),
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('Paystack payment initialization failed', [
                    'error' => $error,
                    'amount' => $amount,
                    'currency' => $currency,
                ]);
                
                throw new \Exception($error['message'] ?? 'Failed to initialize payment');
            }

            $data = $response->json('data');

            $this->logPaymentAction('initialize', [
                'reference' => $reference,
                'amount' => $amount,
                'status' => 'success',
            ]);

            return [
                'reference' => $reference,
                'authorization_url' => $data['authorization_url'],
                'access_code' => $data['access_code'],
                'status' => 'pending',
            ];
        } catch (\Exception $e) {
            $this->logPaymentAction('initialize', [
                'reference' => $reference ?? null,
                'amount' => $amount,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Verify a payment transaction
     */
    public function verifyPayment(string $reference): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/transaction/verify/{$reference}");

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('Paystack payment verification failed', [
                    'error' => $error,
                    'reference' => $reference,
                ]);
                
                throw new \Exception($error['message'] ?? 'Failed to verify payment');
            }

            $data = $response->json('data');

            $this->logPaymentAction('verify', [
                'reference' => $reference,
                'status' => $data['status'],
                'amount' => $data['amount'] / 100, // Convert from kobo
            ]);

            return [
                'reference' => $data['reference'],
                'status' => $this->mapPaystackStatus($data['status']),
                'amount' => $data['amount'] / 100, // Convert from kobo to main currency
                'currency' => $data['currency'],
                'paid_at' => $data['paid_at'] ?? null,
                'gateway_response' => $data['gateway_response'],
                'customer' => $data['customer'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ];
        } catch (\Exception $e) {
            $this->logPaymentAction('verify', [
                'reference' => $reference,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Release funds from escrow (transfer to company)
     */
    public function releaseFunds(string $reference, array $recipientAccount): array
    {
        try {
            // First, verify the payment exists
            $paymentData = $this->verifyPayment($reference);
            
            if ($paymentData['status'] !== 'success') {
                throw new \Exception('Cannot release funds from unsuccessful payment');
            }

            // Create transfer recipient
            $recipientResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/transferrecipient", [
                'type' => 'nuban',
                'name' => $recipientAccount['name'] ?? 'Company Account',
                'account_number' => $recipientAccount['account_number'],
                'bank_code' => $recipientAccount['bank_code'],
                'currency' => 'NGN',
            ]);

            if (!$recipientResponse->successful()) {
                throw new \Exception('Failed to create transfer recipient');
            }

            $recipientData = $recipientResponse->json('data');

            // Initiate transfer
            $amountInKobo = (int)($paymentData['amount'] * 100);
            
            $transferResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/transfer", [
                'source' => 'balance',
                'amount' => $amountInKobo,
                'recipient' => $recipientData['recipient_code'],
                'reason' => 'Escrow release - Milestone payment',
                'reference' => $this->generateReference(),
            ]);

            if (!$transferResponse->successful()) {
                $error = $transferResponse->json();
                Log::error('Paystack transfer failed', [
                    'error' => $error,
                    'reference' => $reference,
                ]);
                throw new \Exception($error['message'] ?? 'Failed to initiate transfer');
            }

            $transferData = $transferResponse->json('data');

            $this->logPaymentAction('release', [
                'original_reference' => $reference,
                'transfer_reference' => $transferData['reference'],
                'amount' => $paymentData['amount'],
                'status' => 'success',
            ]);

            return [
                'transfer_reference' => $transferData['reference'],
                'original_reference' => $reference,
                'amount' => $paymentData['amount'],
                'status' => 'success',
                'recipient' => $recipientData,
            ];
        } catch (\Exception $e) {
            $this->logPaymentAction('release', [
                'reference' => $reference,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Refund a payment
     */
    public function refundPayment(string $reference): array
    {
        try {
            $paymentData = $this->verifyPayment($reference);
            
            if ($paymentData['status'] !== 'success') {
                throw new \Exception('Cannot refund unsuccessful payment');
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/refund", [
                'transaction' => $reference,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('Paystack refund failed', [
                    'error' => $error,
                    'reference' => $reference,
                ]);
                throw new \Exception($error['message'] ?? 'Failed to process refund');
            }

            $data = $response->json('data');

            $this->logPaymentAction('refund', [
                'reference' => $reference,
                'amount' => $paymentData['amount'],
                'status' => 'success',
            ]);

            return [
                'reference' => $data['transaction']['reference'],
                'amount' => $paymentData['amount'],
                'status' => 'success',
                'refund_reference' => $data['reference'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logPaymentAction('refund', [
                'reference' => $reference,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate unique payment reference
     */
    protected function generateReference(): string
    {
        return 'PAY_' . Str::upper(Str::random(16)) . '_' . time();
    }

    /**
     * Convert amount to kobo (Paystack's smallest currency unit)
     */
    protected function convertToKobo(float $amount, string $currency): int
    {
        // For NGN, convert to kobo (multiply by 100)
        // For other currencies, check if they need conversion
        if ($currency === 'NGN') {
            return (int)($amount * 100);
        }
        
        // For other currencies, assume similar conversion (cents, pesewas, etc.)
        return (int)($amount * 100);
    }

    /**
     * Map Paystack status to our internal status
     */
    protected function mapPaystackStatus(string $paystackStatus): string
    {
        return match(strtolower($paystackStatus)) {
            'success' => 'success',
            'failed' => 'failed',
            'pending' => 'pending',
            'reversed' => 'refunded',
            default => 'pending',
        };
    }

    /**
     * Get list of banks
     */
    public function getBanks(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/bank");

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('Paystack get banks failed', [
                    'error' => $error,
                ]);
                throw new \Exception($error['message'] ?? 'Failed to fetch banks');
            }

            $data = $response->json('data');
            return $data ?? [];
        } catch (\Exception $e) {
            Log::error('Paystack get banks error', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Resolve/verify bank account number
     */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/bank/resolve", [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                Log::error('Paystack resolve account failed', [
                    'error' => $error,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                ]);
                throw new \Exception($error['message'] ?? 'Failed to resolve account');
            }

            $data = $response->json('data');
            return [
                'account_number' => $data['account_number'] ?? $accountNumber,
                'account_name' => $data['account_name'] ?? '',
                'bank_id' => $data['bank_id'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Paystack resolve account error', [
                'error' => $e->getMessage(),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);
            throw $e;
        }
    }
}

