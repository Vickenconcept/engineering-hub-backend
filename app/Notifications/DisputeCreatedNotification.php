<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisputeCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Dispute $dispute
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $project = $this->dispute->project;
        $milestone = $this->dispute->milestone;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;

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
}
