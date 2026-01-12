<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppealSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Company $company,
        public ?string $appealMessage = null
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $user = $this->company->user;
        $isAdmin = $notifiable->isAdmin();

        if ($isAdmin) {
            // Notify admin about the appeal
            $message = (new MailMessage)
                ->subject('New Suspension Appeal Received')
                ->greeting("Hello Admin,")
                ->line("A new suspension appeal has been submitted by **{$this->company->company_name}**.")
                ->line("**Company Details:**")
                ->line("- **Company Name:** {$this->company->company_name}")
                ->line("- **Registration Number:** {$this->company->registration_number}")
                ->line("- **Contact:** {$user->name} ({$user->email})")
                ->line("- **Current Status:** " . ucfirst($this->company->status));

            if ($this->company->suspension_reason) {
                $message->line("**Original Suspension Reason:**")
                    ->line($this->company->suspension_reason);
            }

            if ($this->appealMessage) {
                $message->line("**Appeal Message:**")
                    ->line($this->appealMessage);
            }

            $message->action('Review Appeal', url('/admin/companies/' . $this->company->id))
                ->line('Please review the appeal and take appropriate action.');

            return $message;
        } else {
            // Confirm to company that appeal was submitted
            return (new MailMessage)
                ->subject('Appeal Submitted Successfully')
                ->greeting("Hello {$user->name},")
                ->line("Your appeal for **{$this->company->company_name}** has been submitted successfully.")
                ->line("**What happens next:**")
                ->line("- Our support team will review your appeal")
                ->line("- You will be contacted via email with the decision")
                ->line("- Please do not submit multiple appeals")
                ->action('View Your Profile', url('/settings'))
                ->line('Thank you for your patience.');
        }
    }
}
