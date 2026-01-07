<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VideoMeeting\GoogleMeetService;
use Illuminate\Support\Facades\Log;

class TestGoogleMeet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:google-meet';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Google Meet service configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Google Meet Service Configuration...');
        $this->newLine();

        // Check environment variables
        $this->info('Checking environment variables:');
        $hasEnvCredentials = !empty(env('GOOGLE_APPLICATION_CREDENTIALS_JSON'));
        $hasConfigPath = !empty(config('services.google.credentials_path'));
        $hasConfigJson = !empty(config('services.google.credentials_json'));
        
        $this->line('  GOOGLE_APPLICATION_CREDENTIALS_JSON: ' . ($hasEnvCredentials ? '✓ Set' : '✗ Not set'));
        $this->line('  config(services.google.credentials_path): ' . ($hasConfigPath ? '✓ Set' : '✗ Not set'));
        $this->line('  config(services.google.credentials_json): ' . ($hasConfigJson ? '✓ Set' : '✗ Not set'));
        $this->newLine();

        if (!$hasEnvCredentials && !$hasConfigPath && !$hasConfigJson) {
            $this->error('No Google credentials found!');
            $this->line('Please set GOOGLE_APPLICATION_CREDENTIALS_JSON in your .env file');
            return 1;
        }

        // Try to initialize the service
        $this->info('Attempting to initialize GoogleMeetService...');
        try {
            $service = app(GoogleMeetService::class);
            $this->info('✓ GoogleMeetService initialized successfully!');
            $this->newLine();
            $this->info('Configuration looks good. The service should work when consultations are paid.');
            return 0;
        } catch (\Exception $e) {
            $this->error('✗ Failed to initialize GoogleMeetService');
            $this->newLine();
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();
            $this->line('File: ' . $e->getFile());
            $this->line('Line: ' . $e->getLine());
            $this->newLine();
            $this->line('Check the logs for more details: storage/logs/laravel.log');
            return 1;
        }
    }
}
