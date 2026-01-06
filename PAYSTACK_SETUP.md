# Paystack Payment Integration Setup

## Environment Configuration

Add the following to your `.env` file:

```env
# Paystack Configuration
PAYSTACK_PUBLIC_KEY=your_paystack_public_key_here
PAYSTACK_SECRET_KEY=your_paystack_secret_key_here
PAYSTACK_BASE_URL=https://api.paystack.co
```

### Getting Paystack API Keys

1. **Sign up** at [Paystack](https://paystack.com/)
2. Go to **Settings** → **API Keys & Webhooks**
3. Copy your **Public Key** and **Secret Key**
4. For testing, use the **Test Keys**
5. For production, use the **Live Keys**

## Payment Flow

### 1. Consultation Payment Flow

1. Client books a consultation
2. Client calls `POST /api/client/consultations/{id}/pay`
3. API returns `payment_url` and `reference`
4. Frontend redirects user to `payment_url`
5. User completes payment on Paystack
6. Paystack redirects to callback URL
7. API verifies payment via `POST /api/payments/verify`
8. Consultation status updated to "paid"

### 2. Milestone Escrow Flow

1. Company creates milestones for a project
2. Client calls `POST /api/client/milestones/{id}/fund`
3. API returns `payment_url` and `reference`
4. Frontend redirects user to `payment_url`
5. User completes payment on Paystack
6. Paystack redirects to callback URL
7. API verifies payment and creates escrow record
8. Milestone status updated to "funded"
9. Company can now upload evidence and submit milestone
10. Client approves/rejects milestone
11. Admin releases escrow funds to company

### 3. Escrow Release Flow

1. Milestone is approved by client
2. Admin calls `POST /api/admin/milestones/{id}/release` with recipient account details
3. API initiates transfer via Paystack Transfer API
4. Funds transferred to company account
5. Escrow status updated to "released"

## API Endpoints

### Payment Endpoints

#### Initialize Consultation Payment
```
POST /api/client/consultations/{id}/pay
Authorization: Bearer {token}

Response:
{
  "success": true,
  "message": "Payment initialized...",
  "data": {
    "payment_url": "https://checkout.paystack.com/...",
    "reference": "PAY_ABC123...",
    "consultation": { ... }
  }
}
```

#### Initialize Milestone Escrow Payment
```
POST /api/client/milestones/{id}/fund
Authorization: Bearer {token}

Response:
{
  "success": true,
  "message": "Payment initialized...",
  "data": {
    "payment_url": "https://checkout.paystack.com/...",
    "reference": "PAY_XYZ789...",
    "milestone": { ... }
  }
}
```

#### Verify Payment
```
POST /api/payments/verify
Content-Type: application/json

{
  "reference": "PAY_ABC123..."
}

Response:
{
  "success": true,
  "message": "Payment successful...",
  "data": { ... }
}
```

#### Release Escrow Funds (Admin Only)
```
POST /api/admin/milestones/{id}/release
Authorization: Bearer {admin_token}

{
  "override": false,
  "recipient_account": {
    "account_number": "0123456789",
    "bank_code": "058",
    "name": "Company Name"
  }
}
```

#### Paystack Webhook
```
POST /api/payments/webhook
X-Paystack-Signature: {signature}

(This is automatically called by Paystack)
```

## Webhook Configuration

1. Go to Paystack Dashboard → **Settings** → **API Keys & Webhooks**
2. Add webhook URL: `https://yourdomain.com/api/payments/webhook`
3. Select events to listen to:
   - `charge.success` - Payment successful
   - `transfer.success` - Transfer successful

## Testing

### Test Cards

Use these test cards from Paystack:

- **Successful Payment**: `4084084084084081`
- **Declined Payment**: `5060666666666666666`
- **Insufficient Funds**: `5060666666666666666`

Use any future expiry date and any CVV.

### Test Bank Accounts (for Transfers)

Use Paystack's test bank codes:
- Bank Code: `058` (GTBank)
- Account Number: Any valid format (e.g., `0123456789`)

## Bank Codes Reference

Common Nigerian bank codes for transfers:

- GTBank: `058`
- Access Bank: `044`
- First Bank: `011`
- UBA: `033`
- Zenith Bank: `057`
- FCMB: `214`
- Union Bank: `032`
- Stanbic IBTC: `221`

Full list available at: https://paystack.com/docs/payments/transfers/#bank-codes

## Error Handling

The payment service throws exceptions for:
- Invalid API keys
- Network errors
- Payment failures
- Invalid references

All errors are logged and return appropriate HTTP status codes.

## Security

1. **Never expose secret key** in frontend code
2. **Always verify webhook signatures** (implemented)
3. **Use HTTPS** in production
4. **Validate payment amounts** server-side
5. **Log all payment actions** (audit trail implemented)

## Troubleshooting

### Payment not initializing
- Check API keys in `.env`
- Verify keys are correct (test vs live)
- Check network connectivity

### Webhook not receiving events
- Verify webhook URL is correct
- Check webhook URL is publicly accessible
- Verify signature validation
- Check server logs for errors

### Transfer failing
- Verify recipient account details
- Check bank code is correct
- Ensure sufficient balance in Paystack account
- Check transfer limits

## Support

- [Paystack Documentation](https://paystack.com/docs)
- [Paystack Support](https://paystack.com/support)

