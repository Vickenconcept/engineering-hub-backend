# UUID Migration Guide

## Overview

All database tables have been updated to use UUIDs instead of auto-incrementing integer IDs. This provides better security and makes URLs more meaningful.

## Changes Made

### 1. Migrations Updated

All migration files have been updated to use UUIDs:

- ✅ `users` table - Primary key changed to UUID
- ✅ `companies` table - Primary key and foreign keys changed to UUID
- ✅ `consultations` table - Primary key and foreign keys changed to UUID
- ✅ `projects` table - Primary key and foreign keys changed to UUID
- ✅ `milestones` table - Primary key and foreign keys changed to UUID
- ✅ `escrows` table - Primary key and foreign keys changed to UUID
- ✅ `milestone_evidence` table - Primary key and foreign keys changed to UUID
- ✅ `disputes` table - Primary key and foreign keys changed to UUID
- ✅ `audit_logs` table - Primary key, foreign keys, and entity_id changed to UUID/string
- ✅ `personal_access_tokens` table - tokenable_id changed to UUID
- ✅ `sessions` table - user_id changed to UUID

### 2. Models Updated

All models have been updated with:

- ✅ `protected $keyType = 'string';`
- ✅ `public $incrementing = false;`
- ✅ `boot()` method to auto-generate UUIDs on creation

Models updated:
- User
- Company
- Consultation
- Project
- Milestone
- Escrow
- MilestoneEvidence
- Dispute
- AuditLog

### 3. Controllers Updated

All controller methods that accept IDs have been updated from `int $id` to `string $id`:

- ✅ ProjectController
- ✅ Admin/MilestoneController
- ✅ Admin/CompanyController
- ✅ Admin/DisputeController
- ✅ Client/MilestoneController
- ✅ Client/ConsultationController
- ✅ Client/CompanyController
- ✅ Client/ProjectController
- ✅ Company/ProjectController
- ✅ Company/MilestoneController
- ✅ Company/ConsultationController

### 4. Services Updated

- ✅ AuditLogService - All methods updated to use `string` instead of `int` for IDs

## How to Run Migration

### Fresh Migration (Recommended for Development)

```bash
cd backend
php artisan migrate:fresh
```

This will:
1. Drop all tables
2. Recreate all tables with UUID primary keys
3. Set up all relationships with UUID foreign keys

### If You Have Existing Data

⚠️ **WARNING**: If you have existing data, you'll need to migrate it manually. UUID migration is not backward compatible with integer IDs.

**Option 1: Fresh Start (Development)**
```bash
php artisan migrate:fresh --seed
```

**Option 2: Manual Migration (Production)**
1. Export existing data
2. Run `migrate:fresh`
3. Import data with UUID mappings
4. Update foreign key references

## URL Changes

URLs will now use UUIDs instead of integers:

**Before:**
- `http://localhost:3000/projects/1`
- `http://localhost:3000/milestones/1`

**After:**
- `http://localhost:3000/projects/550e8400-e29b-41d4-a716-446655440000`
- `http://localhost:3000/milestones/550e8400-e29b-41d4-a716-446655440000`

## Frontend Updates Needed

The frontend should automatically work with UUIDs since they're strings. However, you may need to update:

1. **Type Definitions** - Update TypeScript interfaces if they specify `id: number` to `id: string`
2. **URL Parsing** - Ensure URL parsing handles UUIDs correctly
3. **Form Validation** - If any forms validate IDs as numbers, update to accept UUIDs

## Testing Checklist

After running migrations:

- [ ] User registration works
- [ ] User login works
- [ ] Company profile creation works
- [ ] Consultation booking works
- [ ] Project creation works
- [ ] Milestone creation works
- [ ] Escrow funding works
- [ ] Payment callbacks work
- [ ] Admin functions work
- [ ] All relationships load correctly

## Notes

- UUIDs are automatically generated when creating new records
- All foreign key relationships use UUIDs
- Route model binding will automatically work with UUIDs
- No changes needed to Eloquent relationships - they work the same way

## Example UUID Format

UUIDs follow the standard format:
```
550e8400-e29b-41d4-a716-446655440000
```

36 characters total (32 hex digits + 4 hyphens)

## Benefits

1. **Security**: UUIDs don't reveal information about record count or order
2. **Distributed Systems**: UUIDs can be generated without database coordination
3. **URLs**: More meaningful and professional-looking URLs
4. **No Collisions**: Virtually impossible to have duplicate UUIDs

## Troubleshooting

### Issue: "Invalid UUID format"
- Ensure all ID fields are using UUID strings, not integers
- Check that models have `$keyType = 'string'` and `$incrementing = false`

### Issue: Foreign key constraint fails
- Ensure all foreign keys are UUIDs matching the referenced table's primary key
- Run `php artisan migrate:fresh` to ensure all tables are in sync

### Issue: Route model binding not working
- Laravel automatically handles UUIDs in route model binding
- Ensure route parameters are defined as `{id}` not `{id:int}`

