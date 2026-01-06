# Paystack Payment Integration Setup

## Configuration

Paystack API keys are configured in your `.env` file. The following environment variables are required:

```env
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxxxxx
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxx
PAYSTACK_BASE_URL=https://api.paystack.co
```

## Where to Get Your Keys

1. **Sign up** at [https://paystack.com](https://paystack.com)
2. Go to **Settings** → **API Keys & Webhooks**
3. Copy your **Public Key** and **Secret Key**
4. For testing, use the **Test Keys** (they start with `pk_test_` and `sk_test_`)
5. For production, use the **Live Keys** (they start with `pk_live_` and `sk_live_`)

## Configuration File

The keys are loaded from `backend/config/services.php`:

```php
'paystack' => [
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
],
```

## How Payments Work

1. **Consultation Payments**: When a client books a consultation, they pay via Paystack. The payment is verified on the backend callback.

2. **Milestone Escrow**: When a client funds a milestone, the payment goes into escrow. The admin can release funds to the company after milestone approval.

3. **Payment Callback**: After payment, Paystack redirects to `/payment/callback` which verifies the payment and updates the database, then redirects to the frontend.

## Testing

- Use Paystack's test cards: `4084084084084081` (success) or `5060666666666666666` (failure)
- Use any CVV and future expiry date
- Use any OTP for 3D Secure

## Important Notes

- **Never commit your `.env` file** to version control
- Keep your **Secret Key** secure - it should never be exposed in frontend code
- The **Public Key** can be used in frontend if needed (currently not used)
- All payment processing happens on the backend for security
