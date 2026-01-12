<?php

namespace App\Notifications;

use App\Models\Milestone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MilestoneRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Milestone $milestone,
        public ?string $rejectionReason = null
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

        $message = (new MailMessage)
            ->subject($isCompany 
                ? 'Milestone Rejected by Client' 
                : 'Milestone Rejected')
            ->greeting($isCompany ? "Hello {$company->company_name}," : "Hello {$client->name},")
            ->line($isCompany
                ? "The milestone \"{$this->milestone->title}\" has been rejected by {$client->name}."
                : "You have rejected the milestone \"{$this->milestone->title}\".")
            ->line("**Milestone Details:**")
            ->line("- **Title:** {$this->milestone->title}")
            ->line("- **Amount:** ₦" . number_format($this->milestone->amount, 2))
            ->line("- **Project:** {$project->title}");

        if ($this->rejectionReason) {
            $message->line("**Reason for Rejection:**")
                ->line($this->rejectionReason);
        }

        if ($isCompany) {
            $message->line("Please review the feedback and resubmit the milestone with the necessary revisions.");
        }

        $message->action('View Milestone', url('/milestones/' . $this->milestone->id))
            ->line('Thank you for using our platform!');

        return $message;
    }
}
