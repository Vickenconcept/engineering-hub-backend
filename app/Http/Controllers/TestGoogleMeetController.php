<?php

namespace App\Http\Controllers;

use App\Services\VideoMeeting\GoogleMeetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TestGoogleMeetController extends Controller
{
    public function test()
    {
        try {
            $googleMeetService = new GoogleMeetService();
            
            // Test data
            $consultationId = 'test-' . uniqid();
            $startTime = new \DateTime('+1 day 10:00:00'); // Tomorrow at 10 AM
            $durationMinutes = 30;
            $clientEmail = 'testuser@gmail.com';
            $companyEmail = 'vickenconcept@gmail.com';
            $title = 'Test Consultation Meeting';
            $description = 'This is a test meeting to verify Google Meet integration';
            
            Log::info('TestGoogleMeet: Starting test', [
                'consultation_id' => $consultationId,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
            ]);
            
            $result = $googleMeetService->createMeeting(
                consultationId: $consultationId,
                startTime: $startTime,
                durationMinutes: $durationMinutes,
                clientEmail: $clientEmail,
                companyEmail: $companyEmail,
                title: $title,
                description: $description
            );
            
            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <title>Google Meet Test - Success</title>
                <style>
                    body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
                    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0; }
                    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin: 20px 0; }
                    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
                    a { color: #007bff; text-decoration: none; }
                    a:hover { text-decoration: underline; }
                    code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
                </style>
            </head>
            <body>
                <h1>✅ Google Meet Test - Success!</h1>
                <div class='success'>
                    <h2>Meeting Created Successfully</h2>
                    <p><strong>Consultation ID:</strong> <code>{$consultationId}</code></p>
                    <p><strong>Calendar Event ID:</strong> <code>{$result['calendar_event_id']}</code></p>
                    <p><strong>Meeting Link:</strong> <a href='{$result['meeting_link']}' target='_blank'>{$result['meeting_link']}</a></p>
                    <p><strong>Start Time:</strong> {$startTime->format('Y-m-d H:i:s')}</p>
                    <p><strong>Duration:</strong> {$durationMinutes} minutes</p>
                </div>
                <div class='info'>
                    <h3>Test Details</h3>
                    <p><strong>Client Email:</strong> {$clientEmail}</p>
                    <p><strong>Company Email:</strong> {$companyEmail}</p>
                    <p><strong>Title:</strong> {$title}</p>
                </div>
                <p><a href='/test-google-meet'>Run Test Again</a></p>
            </body>
            </html>
            ";
            
            return $html;
            
        } catch (\Exception $e) {
            Log::error('TestGoogleMeet: Test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <title>Google Meet Test - Error</title>
                <style>
                    body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
                    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
                    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin: 20px 0; }
                    code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; display: block; white-space: pre-wrap; margin: 10px 0; }
                    a { color: #007bff; text-decoration: none; }
                    a:hover { text-decoration: underline; }
                </style>
            </head>
            <body>
                <h1>❌ Google Meet Test - Error</h1>
                <div class='error'>
                    <h2>Failed to Create Meeting</h2>
                    <p><strong>Error:</strong></p>
                    <code>{$e->getMessage()}</code>
                </div>
                <div class='info'>
                    <h3>Troubleshooting</h3>
                    <ul>
                        <li>Check that the credentials file exists at <code>storage/app/google/google-credentials.json</code></li>
                        <li>Verify the calendar is shared with the service account email</li>
                        <li>Ensure Google Meet is enabled for the calendar</li>
                        <li>Check the Laravel logs for more details</li>
                    </ul>
                </div>
                <p><a href='/test-google-meet'>Try Again</a></p>
            </body>
            </html>
            ";
            
            return $html;
        }
    }
}

