<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CompanySuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public ?string $reason = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->company->user;

        Log::info('Sending CompanySuspendedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'company_id' => $this->company->id,
            'subject' => 'Company Account Suspended',
        ]);

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

    public function toArray($notifiable): array
    {
        $user = $this->company->user;

        return [
            'type' => 'company_suspended',
            'title' => 'Company Account Suspended',
            'message' => "Your company account for **{$this->company->company_name}** has been suspended.",
            'data' => [
                'company_id' => $this->company->id,
                'company_name' => $this->company->company_name,
                'reason' => $this->reason,
                'action_url' => '/settings',
            ],
        ];
    }
}
