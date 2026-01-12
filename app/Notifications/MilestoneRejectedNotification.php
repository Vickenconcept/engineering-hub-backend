<?php

namespace App\Notifications;

use App\Models\Milestone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class MilestoneRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Milestone $milestone,
        public ?string $rejectionReason = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $project = $this->milestone->project;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;

        $subject = $isCompany 
            ? 'Milestone Rejected by Client' 
            : 'Milestone Rejected';

        Log::info('Sending MilestoneRejectedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'milestone_id' => $this->milestone->id,
            'subject' => $subject,
            'is_company' => $isCompany,
        ]);

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

    public function toArray($notifiable): array
    {
        $project = $this->milestone->project;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;

        return [
            'type' => 'milestone_rejected',
            'title' => $isCompany 
                ? 'Milestone Rejected by Client' 
                : 'Milestone Rejected',
            'message' => $isCompany
                ? "The milestone \"{$this->milestone->title}\" has been rejected by {$client->name}."
                : "You have rejected the milestone \"{$this->milestone->title}\".",
            'data' => [
                'milestone_id' => $this->milestone->id,
                'milestone_title' => $this->milestone->title,
                'milestone_amount' => $this->milestone->amount,
                'project_id' => $project->id,
                'project_title' => $project->title,
                'rejection_reason' => $this->rejectionReason,
                'action_url' => '/milestones/' . $this->milestone->id,
            ],
        ];
    }
}
