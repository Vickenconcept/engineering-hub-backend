# Google Meet Integration Setup Guide

## Overview
Google Meet integration automatically generates meeting links when consultations are paid, creates Google Calendar events, and sends calendar invites to clients and companies.

## Prerequisites
1. Google Cloud Platform account
2. Google Workspace account (or personal Gmail with Calendar API enabled)

## Step 1: Create Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the **Google Calendar API**:
   - Navigate to "APIs & Services" > "Library"
   - Search for "Google Calendar API"
   - Click "Enable"

## Step 2: Create Service Account (Recommended for Production)

### Option A: Service Account (Recommended)
1. Go to "APIs & Services" > "Credentials"
2. Click "Create Credentials" > "Service Account"
3. Fill in details:
   - Name: `engineering-hub-meet`
   - Description: `Service account for Google Meet integration`
4. Click "Create and Continue"
5. Skip role assignment (click "Continue")
6. Click "Done"
7. Click on the created service account
8. Go to "Keys" tab
9. Click "Add Key" > "Create new key"
10. Choose "JSON" format
11. Download the JSON file

### Option B: OAuth 2.0 Client (For Testing)
1. Go to "APIs & Services" > "Credentials"
2. Click "Create Credentials" > "OAuth client ID"
3. Configure consent screen if prompted
4. Choose "Web application"
5. Add authorized redirect URIs
6. Download credentials

## Step 3: Configure Service Account (If using Service Account)

1. **Share Calendar with Service Account:**
   - Open Google Calendar
   - Go to Settings > Settings for my calendars
   - Select your calendar
   - Go to "Share with specific people"
   - Add the service account email (found in the JSON file as `client_email`)
   - Give it "Make changes to events" permission
   
2. **Enable Google Meet for the Calendar:**
   - In the same calendar settings
   - Ensure "Google Meet" is enabled/available
   - If using a Google Workspace account, ensure Google Meet is enabled for your organization

2. **Enable Domain-wide Delegation (Optional):**
   - Only needed if using Google Workspace
   - In Service Account, enable "Enable Google Workspace Domain-wide Delegation"
   - Add required scopes:
     - `https://www.googleapis.com/auth/calendar`

## Step 4: Configure Laravel

### Add to `.env` file:

**Recommended: File Path Method (Easier)**

1. Place the JSON file in `backend/storage/app/google/google-credentials.json` (or `backend/storage/app/google-credentials.json`)
2. Add to `.env`:
   ```env
   GOOGLE_APPLICATION_CREDENTIALS=storage/app/google/google-credentials.json
   ```
   Or leave it empty to use the default path:
   ```env
   # Uses default: storage/app/google/google-credentials.json
   ```
3. The file is already in `.gitignore`

**Alternative: JSON String Method**

If you prefer to use JSON string in `.env`:
```env
GOOGLE_APPLICATION_CREDENTIALS_JSON={"type":"service_account","project_id":"your-project",...}
```

**Important:** 
- The JSON must be the ENTIRE content from your service account file
- Must be on a single line
- Should be 1000+ characters long
- Escape quotes if needed: `"` becomes `\"`

**Optional: Custom calendar ID**
```env
GOOGLE_CALENDAR_ID=primary
```

**Note:** If you're using a personal Gmail account (not Google Workspace), the API cannot programmatically create Meet links. The system will automatically use `https://meet.google.com/new` to generate a new meeting link as a fallback. Each consultation will get a unique meeting link.

## Step 5: Test the Integration

1. Create a test consultation
2. Complete payment
3. Check that:
   - Meeting link is generated in database
   - Google Calendar event is created
   - Calendar invites are sent to client and company emails

## Troubleshooting

### Error: "Google credentials not configured"
- Check that `GOOGLE_APPLICATION_CREDENTIALS_JSON` or `GOOGLE_APPLICATION_CREDENTIALS` is set in `.env`
- Verify JSON format is correct

### Error: "Failed to create Google Meet"
- Check that Google Calendar API is enabled
- Verify service account has calendar access
- Check that calendar is shared with service account (if using service account)

### Error: "Insufficient permissions"
- Ensure service account has "Make changes to events" permission on the calendar
- Check that Calendar API is enabled in Google Cloud Console

### Meeting links not generated
- Check Laravel logs: `storage/logs/laravel.log`
- Verify client and company have email addresses
- Ensure `scheduled_at` is a valid future date

## Features

✅ **Automatic Meeting Generation**
- Meeting links created when consultation payment is successful
- Links stored in `meeting_link` field

✅ **Calendar Events**
- Google Calendar events created automatically
- Events include client and company as attendees
- Calendar event ID stored in `calendar_event_id` field

✅ **Email Invites**
- Automatic calendar invites sent to client and company
- Includes meeting link and details

✅ **Reminders**
- Email reminders 24 hours before meeting
- Email reminders 1 hour before meeting

✅ **Error Handling**
- Payment succeeds even if meeting generation fails
- Errors logged for debugging
- Meeting can be generated manually later if needed

## API Usage

The service is automatically called when:
- Consultation payment is verified and successful

Manual usage (if needed):
```php
use App\Services\VideoMeeting\GoogleMeetService;

$service = app(GoogleMeetService::class);
$result = $service->createMeeting(
    consultationId: $consultation->id,
    startTime: $consultation->scheduled_at,
    durationMinutes: 30,
    clientEmail: $client->email,
    companyEmail: $company->email,
    title: "Consultation",
    description: "Consultation meeting"
);
```

## Security Notes

- Keep credentials secure - never commit to git
- Use service account for production
- Rotate credentials periodically
- Monitor API usage in Google Cloud Console

