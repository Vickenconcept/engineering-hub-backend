<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class DisputeCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Dispute $dispute
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $project = $this->dispute->project;
        $milestone = $this->dispute->milestone;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;

        $subject = $isCompany 
            ? 'Dispute Filed Against Your Milestone' 
            : 'Dispute Filed Successfully';

        Log::info('Sending DisputeCreatedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'dispute_id' => $this->dispute->id,
            'subject' => $subject,
            'is_company' => $isCompany,
        ]);

        return (new MailMessage)
            ->subject($isCompany 
                ? 'Dispute Filed Against Your Milestone' 
                : 'Dispute Filed Successfully')
            ->greeting($isCompany ? "Hello {$company->company_name}," : "Hello {$client->name},")
            ->line($isCompany
                ? "A dispute has been filed by {$client->name} regarding milestone \"{$milestone->title}\"."
                : "Your dispute regarding milestone \"{$milestone->title}\" has been filed successfully.")
            ->line("**Dispute Details:**")
            ->line("- **Type:** " . ucfirst(str_replace('_', ' ', $this->dispute->type)))
            ->line("- **Milestone:** {$milestone->title}")
            ->line("- **Project:** {$project->title}")
            ->line("- **Status:** " . ucfirst($this->dispute->status))
            ->line($isCompany
                ? "Please review the dispute and respond accordingly. Our admin team will review and resolve the dispute."
                : "Our admin team will review your dispute and contact you soon.")
            ->action('View Dispute', url('/admin/disputes/' . $this->dispute->id))
            ->line('Thank you for using our platform!');
    }

    public function toArray($notifiable): array
    {
        $project = $this->dispute->project;
        $milestone = $this->dispute->milestone;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;

        return [
            'type' => 'dispute_created',
            'title' => $isCompany 
                ? 'Dispute Filed Against Your Milestone' 
                : 'Dispute Filed Successfully',
            'message' => $isCompany
                ? "A dispute has been filed by {$client->name} regarding milestone \"{$milestone->title}\"."
                : "Your dispute regarding milestone \"{$milestone->title}\" has been filed successfully.",
            'data' => [
                'dispute_id' => $this->dispute->id,
                'dispute_type' => $this->dispute->type,
                'milestone_id' => $milestone->id,
                'milestone_title' => $milestone->title,
                'project_id' => $project->id,
                'project_title' => $project->title,
                'action_url' => '/admin/disputes/' . $this->dispute->id,
            ],
        ];
    }
}
