<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CompanyRejectedNotification extends Notification implements ShouldQueue
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

        Log::info('Sending CompanyRejectedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'company_id' => $this->company->id,
            'subject' => 'Company Profile Review Update',
        ]);

        $message = (new MailMessage)
            ->subject('Company Profile Review Update')
            ->greeting("Hello {$user->name},")
            ->line("We regret to inform you that your company profile for **{$this->company->company_name}** has been rejected during our review process.");

        if ($this->reason) {
            $message->line("**Reason for Rejection:**")
                ->line($this->reason);
        }

        $message->line("**What you can do:**")
            ->line("- Review your profile information")
            ->line("- Update any missing or incorrect details")
            ->line("- Resubmit your profile for review")
            ->line("- Contact support if you have questions")
            ->action('Update Your Profile', url('/settings'))
            ->line('If you believe this is an error, please contact our support team.');

        return $message;
    }

    public function toArray($notifiable): array
    {
        $user = $this->company->user;

        return [
            'type' => 'company_rejected',
            'title' => 'Company Profile Review Update',
            'message' => "We regret to inform you that your company profile for **{$this->company->company_name}** has been rejected during our review process.",
            'data' => [
                'company_id' => $this->company->id,
                'company_name' => $this->company->company_name,
                'reason' => $this->reason,
                'action_url' => '/settings',
            ],
        ];
    }
}
