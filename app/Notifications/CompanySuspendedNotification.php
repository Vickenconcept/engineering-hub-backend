<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanySuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public ?string $reason = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->company->user;

        $message = (new MailMessage)
            ->subject('Company Account Suspended')
            ->greeting("Hello {$user->name},")
            ->line("Your company account for **{$this->company->company_name}** has been suspended.");

        if ($this->reason) {
            $message->line("**Reason for Suspension:**")
                ->line($this->reason);
        }

        $message->line("**What this means:**")
            ->line("- ⚠️ You cannot receive new consultations or projects")
            ->line("- ⚠️ You cannot create milestones or submit work")
            ->line("- ✅ You can still log in to view your account")
            ->line("- ✅ You can appeal this suspension")
            ->line("**What you can do:**")
            ->line("- Review the suspension reason")
            ->line("- Appeal the suspension if you believe it's incorrect")
            ->line("- Contact support for assistance")
            ->action('Appeal Suspension', url('/settings'))
            ->line('If you have any questions, please contact our support team.');

        return $message;
    }
}
