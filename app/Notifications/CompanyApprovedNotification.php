<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->company->user;

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
}
