# API Endpoints Reference

Complete reference for all API endpoints in the Engineering Hub.

## Base URL
```
http://localhost:8000/api
```

## Authentication

All protected endpoints require Bearer token authentication:
```
Authorization: Bearer {token}
```

---

## Public Endpoints

### Authentication

#### Register
```
POST /api/auth/register
Rate Limit: 10 requests/minute

Body:
{
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890",
  "password": "password123",
  "password_confirmation": "password123",
  "role": "client" // client | company | admin
}

Response:
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": { ... },
    "token": "1|..."
  }
}
```

#### Login
```
POST /api/auth/login
Rate Limit: 10 requests/minute

Body:
{
  "email": "john@example.com",
  "password": "password123"
}

Response:
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { ... },
    "token": "1|..."
  }
}
```

---

## Protected Endpoints

### Authentication

#### Logout
```
POST /api/auth/logout
Rate Limit: 60 requests/minute
Requires: Bearer token
```

#### Get Current User
```
GET /api/auth/me
Rate Limit: 60 requests/minute
Requires: Bearer token

Response:
{
  "success": true,
  "message": "User retrieved successfully",
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "role": "client",
    "company": { ... } // if company user
  }
}
```

---

## Client Endpoints

### Consultations

#### List Consultations
```
GET /api/client/consultations
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client

Query Parameters:
- per_page (default: 15)

Response:
{
  "success": true,
  "message": "Consultations retrieved successfully",
  "data": [ ... ],
  "meta": { pagination }
}
```

#### Get Consultation
```
GET /api/client/consultations/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client
```

#### Create Consultation
```
POST /api/client/consultations
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client

Body:
{
  "company_id": 1,
  "scheduled_at": "2024-02-15 14:00:00",
  "duration_minutes": 30,
  "price": 25000.00
}
```

#### Pay for Consultation
```
POST /api/client/consultations/{id}/pay
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client

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

### Projects

#### List Projects
```
GET /api/client/projects
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client
```

#### Get Project
```
GET /api/client/projects/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client
```

#### Create Project
```
POST /api/client/projects
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client

Body:
{
  "consultation_id": 1,
  "title": "My Dream House",
  "description": "3-bedroom house project",
  "location": "Lagos, Nigeria",
  "budget_min": 5000000,
  "budget_max": 7000000
}
```

### Milestones

#### Fund Milestone Escrow
```
POST /api/client/milestones/{id}/fund
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client

Response:
{
  "success": true,
  "data": {
    "payment_url": "https://checkout.paystack.com/...",
    "reference": "PAY_XYZ789...",
    "milestone": { ... }
  }
}
```

#### Approve Milestone
```
POST /api/client/milestones/{id}/approve
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client
```

#### Reject Milestone
```
POST /api/client/milestones/{id}/reject
Rate Limit: 60 requests/minute
Requires: Bearer token, role:client

Body:
{
  "reason": "Work quality not satisfactory"
}
```

---

## Company Endpoints

### Company Profile

#### Get Profile
```
GET /api/company/profile
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

#### Create Profile
```
POST /api/company/profile
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company

Body:
{
  "company_name": "Building Masters Ltd",
  "registration_number": "RC123456",
  "license_documents": [file1, file2],
  "portfolio_links": ["https://example.com/portfolio"],
  "specialization": ["Residential", "Commercial"]
}
```

#### Update Profile
```
PUT /api/company/profile
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

### Consultations

#### List Consultations
```
GET /api/company/consultations
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

#### Get Consultation
```
GET /api/company/consultations/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

#### Complete Consultation
```
POST /api/company/consultations/{id}/complete
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

### Projects

#### List Projects
```
GET /api/company/projects
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

#### Get Project
```
GET /api/company/projects/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

#### Create Milestones
```
POST /api/company/projects/{id}/milestones
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company

Body:
{
  "milestones": [
    {
      "title": "Foundation",
      "description": "Foundation work",
      "amount": 2000000,
      "sequence_order": 1
    },
    {
      "title": "Structure",
      "description": "Building structure",
      "amount": 3000000,
      "sequence_order": 2
    }
  ]
}
```

### Milestones

#### Submit Milestone
```
POST /api/company/milestones/{id}/submit
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company
```

#### Upload Evidence
```
POST /api/company/milestones/{id}/evidence
Rate Limit: 60 requests/minute
Requires: Bearer token, role:company

Body (multipart/form-data):
{
  "type": "image", // image | video | text
  "file": [file],
  "description": "Progress photos"
}
```

---

## Admin Endpoints

### Companies

#### List Companies
```
GET /api/admin/companies
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Query Parameters:
- status (pending | approved | rejected | suspended)
- verified (true | false)
- per_page (default: 15)
```

#### Get Company
```
GET /api/admin/companies/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin
```

#### Approve Company
```
POST /api/admin/companies/{id}/approve
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin
```

#### Reject Company
```
POST /api/admin/companies/{id}/reject
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Body:
{
  "reason": "Invalid documents"
}
```

#### Suspend Company
```
POST /api/admin/companies/{id}/suspend
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Body:
{
  "reason": "Terms violation"
}
```

### Milestones

#### Release Escrow
```
POST /api/admin/milestones/{id}/release
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Body:
{
  "override": false,
  "recipient_account": {
    "account_number": "0123456789",
    "bank_code": "058",
    "name": "Company Name"
  }
}
```

### Disputes

#### List Disputes
```
GET /api/admin/disputes
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Query Parameters:
- status (open | resolved | escalated)
- project_id
- per_page (default: 15)
```

#### Get Dispute
```
GET /api/admin/disputes/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin
```

#### Resolve Dispute
```
POST /api/admin/disputes/{id}/resolve
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Body:
{
  "resolution": "Issue resolved in favor of client",
  "status": "resolved" // resolved | escalated
}
```

### Audit Logs

#### List Audit Logs
```
GET /api/admin/audit-logs
Rate Limit: 60 requests/minute
Requires: Bearer token, role:admin

Query Parameters:
- action
- entity_type
- entity_id
- user_id
- from_date
- to_date
- per_page (default: 50)
```

---

## Shared Endpoints

### Projects

#### Get Project (any authenticated user)
```
GET /api/projects/{id}
Rate Limit: 60 requests/minute
Requires: Bearer token
```

### Disputes

#### Create Dispute
```
POST /api/disputes
Rate Limit: 60 requests/minute
Requires: Bearer token

Body:
{
  "project_id": 1,
  "milestone_id": 2, // optional
  "reason": "Work quality issues"
}
```

---

## Payment Endpoints

### Verify Payment
```
POST /api/payments/verify
Rate Limit: 30 requests/minute
Requires: Bearer token

Body:
{
  "reference": "PAY_ABC123..."
}
```

### Webhook (Paystack)
```
POST /api/payments/webhook
Rate Limit: 100 requests/minute
No authentication (signature verified)

Headers:
X-Paystack-Signature: {signature}
```

---

## Error Responses

### Validation Error (422)
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field": ["Error message"]
  }
}
```

### Unauthorized (401)
```json
{
  "success": false,
  "message": "Unauthorized access"
}
```

### Forbidden (403)
```json
{
  "success": false,
  "message": "Forbidden: Insufficient permissions"
}
```

### Not Found (404)
```json
{
  "success": false,
  "message": "Resource not found"
}
```

### Rate Limit Exceeded (429)
```json
{
  "success": false,
  "message": "Too Many Attempts."
}
```

---

## Testing

### Test Users (from seeder)
- **Admin**: admin@engineeringhub.com / password
- **Client**: client@example.com / password
- **Company**: company@example.com / password

### Test Payment Cards
See `PAYSTACK_SETUP.md` for test cards.

