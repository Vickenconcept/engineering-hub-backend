<?php

namespace App\Notifications;

use App\Models\Milestone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class EscrowReleasedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Milestone $milestone
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
        $escrow = $this->milestone->escrow;

        $netAmount = $escrow->net_amount ?? ($escrow->amount - ($escrow->platform_fee ?? 0));

        $subject = $isCompany 
            ? 'Escrow Funds Released to Your Account' 
            : 'Escrow Funds Released';

        Log::info('Sending EscrowReleasedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'milestone_id' => $this->milestone->id,
            'subject' => $subject,
            'is_company' => $isCompany,
        ]);

        return (new MailMessage)
            ->subject($isCompany 
                ? 'Escrow Funds Released to Your Account' 
                : 'Escrow Funds Released')
            ->greeting($isCompany ? "Hello {$company->company_name}," : "Hello {$client->name},")
            ->line($isCompany
                ? "Escrow funds for milestone \"{$this->milestone->title}\" have been released to your account."
                : "Escrow funds for milestone \"{$this->milestone->title}\" have been released to {$company->company_name}.")
            ->line("**Payment Details:**")
            ->line("- **Milestone:** {$this->milestone->title}")
            ->line("- **Total Amount:** ₦" . number_format($escrow->amount, 2))
            ->line("- **Platform Fee:** ₦" . number_format($escrow->platform_fee ?? 0, 2))
            ->line("- **Net Amount:** ₦" . number_format($netAmount, 2))
            ->line("- **Project:** {$project->title}")
            ->action('View Milestone', url('/milestones/' . $this->milestone->id))
            ->line('Thank you for using our platform!');
    }

    public function toArray($notifiable): array
    {
        $project = $this->milestone->project;
        $isCompany = $notifiable->id === $project->company_id;
        $company = $project->company;
        $client = $project->client;
        $escrow = $this->milestone->escrow;
        $netAmount = $escrow->net_amount ?? ($escrow->amount - ($escrow->platform_fee ?? 0));

        return [
            'type' => 'escrow_released',
            'title' => $isCompany 
                ? 'Escrow Funds Released to Your Account' 
                : 'Escrow Funds Released',
            'message' => $isCompany
                ? "Escrow funds for milestone \"{$this->milestone->title}\" have been released to your account."
                : "Escrow funds for milestone \"{$this->milestone->title}\" have been released to {$company->company_name}.",
            'data' => [
                'milestone_id' => $this->milestone->id,
                'milestone_title' => $this->milestone->title,
                'total_amount' => $escrow->amount,
                'platform_fee' => $escrow->platform_fee ?? 0,
                'net_amount' => $netAmount,
                'project_id' => $project->id,
                'project_title' => $project->title,
                'action_url' => '/milestones/' . $this->milestone->id,
            ],
        ];
    }
}
