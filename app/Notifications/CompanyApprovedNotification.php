<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CompanyApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->company->user;

        Log::info('Sending CompanyApprovedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'company_id' => $this->company->id,
            'subject' => 'Company Profile Approved',
        ]);

        return (new MailMessage)
            ->subject('Company Profile Approved')
            ->greeting("Hello {$user->name},")
            ->line("Great news! Your company profile for **{$this->company->company_name}** has been approved and verified.")
            ->line("**What this means:**")
            ->line("- ✅ Your company is now verified and active")
            ->line("- ✅ You can receive consultation requests")
            ->line("- ✅ You can receive project invitations")
            ->line("- ✅ Clients can book consultations with you")
            ->action('View Your Profile', url('/settings'))
            ->line('Thank you for joining our platform! We look forward to connecting you with clients.');
    }

    public function toArray($notifiable): array
    {
        $user = $this->company->user;

        return [
            'type' => 'company_approved',
            'title' => 'Company Profile Approved',
            'message' => "Great news! Your company profile for **{$this->company->company_name}** has been approved and verified.",
            'data' => [
                'company_id' => $this->company->id,
                'company_name' => $this->company->company_name,
                'action_url' => '/settings',
            ],
        ];
    }
}
