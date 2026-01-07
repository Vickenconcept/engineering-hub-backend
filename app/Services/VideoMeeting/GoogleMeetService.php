<?php

namespace App\Services\VideoMeeting;

use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventDateTime;
use Google_Service_Calendar_ConferenceData;
use Google_Service_Calendar_CreateConferenceRequest;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GoogleMeetService
{
    private $client;
    private $calendarService;
    private $calendarId;

    public function __construct()
    {
        // Lazy initialization - only initialize when actually needed
        // This prevents errors if credentials are not configured yet
    }
    
    /**
     * Ensure client is initialized
     */
    private function ensureInitialized(): void
    {
        if ($this->client === null) {
            $this->initializeClient();
        }
    }

    /**
     * Initialize Google Client and Calendar Service
     */
    private function initializeClient(): void
    {
        try {
            Log::info('GoogleMeetService: Initializing Google Client');
            
            $this->client = new Google_Client();
            
            // Set credentials - try multiple methods
            $credentialsPath = config('services.google.credentials_path');
            $credentialsJson = config('services.google.credentials_json');
            $envCredentials = env('GOOGLE_APPLICATION_CREDENTIALS_JSON');
            
            Log::info('GoogleMeetService: Checking credentials', [
                'has_credentials_path' => !empty($credentialsPath),
                'has_credentials_json' => !empty($credentialsJson),
                'has_env_credentials' => !empty($envCredentials),
            ]);
            
            $credentialsSet = false;
            
            // Method 1: File path from config or default locations (check first)
            $possiblePaths = [];
            
            // Add default locations first (most common)
            $possiblePaths[] = storage_path('app/google/google-credentials.json');
            $possiblePaths[] = storage_path('app/google-credentials.json');
            
            // Then check config path
            if ($credentialsPath) {
                $possiblePaths[] = base_path($credentialsPath);
                $possiblePaths[] = $credentialsPath;
                if (!str_starts_with($credentialsPath, '/') && !str_starts_with($credentialsPath, 'C:')) {
                    $possiblePaths[] = storage_path($credentialsPath);
                }
            }
            
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    Log::info('GoogleMeetService: Using credentials file', ['path' => $path]);
                    try {
                        $this->client->setAuthConfig($path);
                        $credentialsSet = true;
                        Log::info('GoogleMeetService: Credentials file loaded successfully');
                        break;
                    } catch (\Exception $e) {
                        Log::error('GoogleMeetService: Failed to load credentials file', [
                            'path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            
            if (!$credentialsSet) {
                Log::debug('GoogleMeetService: Credentials file not found, checked paths', [
                    'checked_paths' => $possiblePaths,
                ]);
            }
            
            // Method 2: JSON from config (only if file not found)
            if (!$credentialsSet && $credentialsJson) {
                Log::info('GoogleMeetService: Trying credentials from config');
                $decoded = is_string($credentialsJson) ? json_decode($credentialsJson, true) : $credentialsJson;
                if ($decoded && is_array($decoded)) {
                    $this->client->setAuthConfig($decoded);
                    $credentialsSet = true;
                    Log::info('GoogleMeetService: Credentials loaded from config');
                } else {
                    Log::warning('GoogleMeetService: Invalid JSON in config credentials_json');
                }
            }
            
            // Method 3: Environment variable
            if (!$credentialsSet && $envCredentials) {
                Log::info('GoogleMeetService: Using credentials from environment variable', [
                    'credentials_length' => strlen($envCredentials),
                    'first_50_chars' => substr($envCredentials, 0, 50),
                ]);
                
                // Check if it looks like a file path instead of JSON
                if (strlen($envCredentials) < 100 && !str_starts_with($envCredentials, '{')) {
                    Log::warning('GoogleMeetService: GOOGLE_APPLICATION_CREDENTIALS_JSON looks like a file path, not JSON', [
                        'value' => $envCredentials,
                    ]);
                    throw new \Exception('GOOGLE_APPLICATION_CREDENTIALS_JSON appears to be a file path, not JSON. Use GOOGLE_APPLICATION_CREDENTIALS for file paths, or paste the full JSON content.');
                }
                
                $decoded = json_decode($envCredentials, true);
                if ($decoded && is_array($decoded)) {
                    // Validate it has required fields
                    if (!isset($decoded['type']) || $decoded['type'] !== 'service_account') {
                        throw new \Exception('Invalid service account JSON: missing or incorrect "type" field. Expected "service_account"');
                    }
                    if (!isset($decoded['project_id']) || !isset($decoded['private_key']) || !isset($decoded['client_email'])) {
                        throw new \Exception('Invalid service account JSON: missing required fields (project_id, private_key, or client_email)');
                    }
                    
                    $this->client->setAuthConfig($decoded);
                    $credentialsSet = true;
                    Log::info('GoogleMeetService: Credentials loaded successfully from environment variable', [
                        'project_id' => $decoded['project_id'] ?? 'unknown',
                        'client_email' => $decoded['client_email'] ?? 'unknown',
                    ]);
                } else {
                    $jsonError = json_last_error_msg();
                    $jsonErrorCode = json_last_error();
                    Log::error('GoogleMeetService: Invalid JSON in GOOGLE_APPLICATION_CREDENTIALS_JSON', [
                        'json_error' => $jsonError,
                        'json_error_code' => $jsonErrorCode,
                        'credentials_length' => strlen($envCredentials),
                        'first_100_chars' => substr($envCredentials, 0, 100),
                        'last_50_chars' => substr($envCredentials, -50),
                    ]);
                    throw new \Exception(
                        'Invalid JSON in GOOGLE_APPLICATION_CREDENTIALS_JSON: ' . $jsonError . '. ' .
                        'Make sure you paste the ENTIRE JSON content from your service account file as a single line. ' .
                        'The JSON should start with {"type":"service_account",...} and be hundreds of characters long.'
                    );
                }
            }
            
            if (!$credentialsSet) {
                throw new \Exception('Google credentials not configured. Please set GOOGLE_APPLICATION_CREDENTIALS_JSON in .env or configure credentials_path/credentials_json in config/services.php');
            }

            // Set scopes
            $this->client->addScope(Google_Service_Calendar::CALENDAR);
            $this->client->setAccessType('offline');
            $this->client->setPrompt('select_account consent');

            // Initialize Calendar Service
            $this->calendarService = new Google_Service_Calendar($this->client);
            
            // Get calendar ID (use primary calendar by default)
            $this->calendarId = config('services.google.calendar_id', 'primary');
        } catch (\Exception $e) {
            Log::error('GoogleMeetService: Initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to initialize Google Meet service: ' . $e->getMessage());
        }
    }

    /**
     * Create a Google Meet link for a consultation
     * 
     * @param string $consultationId
     * @param \DateTime $startTime
     * @param int $durationMinutes
     * @param string $clientEmail
     * @param string $companyEmail
     * @param string $title
     * @param string|null $description
     * @return array ['meeting_link' => string, 'calendar_event_id' => string]
     * @throws \Exception
     */
    public function createMeeting(
        string $consultationId,
        \DateTime $startTime,
        int $durationMinutes,
        string $clientEmail,
        string $companyEmail,
        string $title,
        ?string $description = null
    ): array {
        $this->ensureInitialized();
        
        try {
            // Calculate end time
            $endTime = (clone $startTime)->modify("+{$durationMinutes} minutes");

            // Create event
            $event = new Google_Service_Calendar_Event();
            $event->setSummary($title);

            // Set start time
            $start = new Google_Service_Calendar_EventDateTime();
            $start->setDateTime($startTime->format('c'));
            $start->setTimeZone($startTime->getTimezone()->getName());
            $event->setStart($start);

            // Set end time
            $end = new Google_Service_Calendar_EventDateTime();
            $end->setDateTime($endTime->format('c'));
            $end->setTimeZone($endTime->getTimezone()->getName());
            $event->setEnd($end);

            // Try to add attendees - but if it fails (service account limitation), continue without them
            // Include emails in description as fallback
            $descriptionWithEmails = ($description ?? "Consultation meeting for consultation ID: {$consultationId}") . 
                "\n\nParticipants:\n- Client: {$clientEmail}\n- Company: {$companyEmail}";
            $event->setDescription($descriptionWithEmails);
            
            // Note: We don't add attendees because service accounts on personal Gmail accounts
            // require Domain-Wide Delegation to invite attendees. This would cause a 403 error.
            // The conference data request should still work to create the Meet link.

            // Enable Google Meet conference
            // Just provide createRequest - Google will default to Meet
            $conferenceData = new Google_Service_Calendar_ConferenceData();
            $createRequest = new Google_Service_Calendar_CreateConferenceRequest();
            $createRequest->setRequestId(uniqid('consultation-' . $consultationId . '-', true));
            $conferenceData->setCreateRequest($createRequest);
            $event->setConferenceData($conferenceData);

            // Set reminders
            $reminders = new \Google_Service_Calendar_EventReminders();
            $reminders->setUseDefault(false);
            $reminderOverrides = [
                new \Google_Service_Calendar_EventReminder(['method' => 'email', 'minutes' => 24 * 60]), // 24 hours before
                new \Google_Service_Calendar_EventReminder(['method' => 'email', 'minutes' => 60]), // 1 hour before
            ];
            $reminders->setOverrides($reminderOverrides);
            $event->setReminders($reminders);

            // Create the event
            // Include sendUpdates to notify attendees (may help trigger Meet link)
            // With "Automatically add video conferences" enabled, adding attendees might trigger Meet link
            $createdEvent = $this->calendarService->events->insert(
                $this->calendarId,
                $event,
                [
                    'conferenceDataVersion' => 1,
                    'sendUpdates' => 'none', // Don't send email notifications, just create the event
                ]
            );

            Log::info('GoogleMeetService: Event created', [
                'consultation_id' => $consultationId,
                'event_id' => $createdEvent->getId(),
                'has_conference_data' => $createdEvent->getConferenceData() !== null,
            ]);

            // Extract meeting link
            $meetingLink = null;
            if ($createdEvent->getConferenceData()) {
                $conferenceData = $createdEvent->getConferenceData();
                $entryPoints = $conferenceData->getEntryPoints();
                
                Log::info('GoogleMeetService: Conference data details', [
                    'consultation_id' => $consultationId,
                    'has_entry_points' => !empty($entryPoints),
                    'entry_points_count' => $entryPoints ? count($entryPoints) : 0,
                ]);
                
                if ($entryPoints) {
                    foreach ($entryPoints as $entryPoint) {
                        Log::debug('GoogleMeetService: Entry point', [
                            'consultation_id' => $consultationId,
                            'type' => $entryPoint->getEntryPointType(),
                            'uri' => $entryPoint->getUri(),
                        ]);
                        
                        if ($entryPoint->getEntryPointType() === 'video') {
                            $meetingLink = $entryPoint->getUri();
                            break;
                        }
                    }
                }
            }

            // Fallback: Check hangoutsLink (older API format)
            if (!$meetingLink && $createdEvent->getHangoutLink()) {
                $meetingLink = $createdEvent->getHangoutLink();
                Log::info('GoogleMeetService: Using hangoutsLink as fallback', [
                    'consultation_id' => $consultationId,
                    'meeting_link' => $meetingLink,
                ]);
            }

            // Fallback: Use /new endpoint
            // This happens on personal Gmail accounts - service accounts can't create Meet links programmatically
            // Note: /new requires user authentication, so we can't extract a real meeting link programmatically
            // Users will need to click the link to create a meeting, then share the actual meeting link with the other party
            $isFallback = false;
            if (!$meetingLink) {
                // Use /new endpoint - when users click it, they'll create a new meeting
                // The first person to click should create the meeting and share the actual link with the other party
                $meetingLink = 'https://meet.google.com/new';
                $isFallback = true;
                
                Log::warning('GoogleMeetService: Using /new fallback (personal Gmail calendar limitation)', [
                    'consultation_id' => $consultationId,
                    'event_id' => $createdEvent->getId(),
                    'meeting_link' => $meetingLink,
                    'note' => 'ConferenceData was not generated - this is expected on personal @gmail.com accounts. Users should click /new to create meeting, then share the actual meeting link.',
                ]);
            }

            Log::info('GoogleMeetService: Meeting created successfully', [
                'consultation_id' => $consultationId,
                'meeting_link' => $meetingLink,
                'event_id' => $createdEvent->getId(),
                'is_fallback' => $isFallback,
            ]);

            return [
                'meeting_link' => $meetingLink,
                'calendar_event_id' => $createdEvent->getId(),
                'is_fallback' => $isFallback,
            ];
        } catch (\Exception $e) {
            Log::error('GoogleMeetService: Failed to create meeting', [
                'consultation_id' => $consultationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception('Failed to create Google Meet: ' . $e->getMessage());
        }
    }

    /**
     * Delete a calendar event (if consultation is cancelled)
     * 
     * @param string $calendarEventId
     * @return bool
     */
    public function deleteMeeting(string $calendarEventId): bool
    {
        $this->ensureInitialized();
        
        try {
            $this->calendarService->events->delete($this->calendarId, $calendarEventId);
            Log::info('GoogleMeetService: Meeting deleted', [
                'event_id' => $calendarEventId,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('GoogleMeetService: Failed to delete meeting', [
                'event_id' => $calendarEventId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

