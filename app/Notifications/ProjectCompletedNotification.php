<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ProjectCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Project $project
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isCompany = $notifiable->id === $this->project->company_id;
        $company = $this->project->company;
        $client = $this->project->client;

        $subject = $isCompany 
            ? 'Project Completed Successfully' 
            : 'Project Marked as Completed';

        Log::info('Sending ProjectCompletedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'project_id' => $this->project->id,
            'subject' => $subject,
            'is_company' => $isCompany,
        ]);

        return (new MailMessage)
            ->subject($isCompany 
                ? 'Project Completed Successfully' 
                : 'Project Marked as Completed')
            ->greeting($isCompany ? "Hello {$company->company_name}," : "Hello {$client->name},")
            ->line($isCompany
                ? "Congratulations! The project \"{$this->project->title}\" has been completed successfully."
                : "The project \"{$this->project->title}\" with {$company->company_name} has been marked as completed.")
            ->line("**Project Details:**")
            ->line("- **Title:** {$this->project->title}")
            ->line("- **Status:** Completed")
            ->line("- **All milestones have been released**")
            ->action('View Project', url('/projects/' . $this->project->id))
            ->line('Thank you for using our platform!');
    }

    public function toArray($notifiable): array
    {
        $isCompany = $notifiable->id === $this->project->company_id;
        $company = $this->project->company;
        $client = $this->project->client;

        return [
            'type' => 'project_completed',
            'title' => $isCompany 
                ? 'Project Completed Successfully' 
                : 'Project Marked as Completed',
            'message' => $isCompany
                ? "Congratulations! The project \"{$this->project->title}\" has been completed successfully."
                : "The project \"{$this->project->title}\" with {$company->company_name} has been marked as completed.",
            'data' => [
                'project_id' => $this->project->id,
                'project_title' => $this->project->title,
                'action_url' => '/projects/' . $this->project->id,
            ],
        ];
    }
}
