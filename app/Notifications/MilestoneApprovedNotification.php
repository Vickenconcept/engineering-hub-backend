<?php

namespace App\Notifications;

use App\Models\Milestone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MilestoneApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Milestone $milestone
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $project = $this->milestone->project;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;

        return (new MailMessage)
            ->subject($isCompany 
                ? 'Milestone Approved by Client' 
                : 'Milestone Approved Successfully')
            ->greeting($isCompany ? "Hello {$company->company_name}," : "Hello {$client->name},")
            ->line($isCompany
                ? "Great news! Your milestone \"{$this->milestone->title}\" has been approved by {$client->name}."
                : "You have approved the milestone \"{$this->milestone->title}\" for your project.")
            ->line("**Milestone Details:**")
            ->line("- **Title:** {$this->milestone->title}")
            ->line("- **Amount:** ₦" . number_format($this->milestone->amount, 2))
            ->line("- **Project:** {$project->title}")
            ->action('View Milestone', url('/milestones/' . $this->milestone->id))
            ->line('Thank you for using our platform!');
    }
}
