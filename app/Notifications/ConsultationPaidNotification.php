<?php

namespace App\Notifications;

use App\Models\Consultation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsultationPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Consultation $consultation
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isClient = $notifiable->id === $this->consultation->client_id;
        $company = $this->consultation->company;
        $client = $this->consultation->client;

        $message = (new MailMessage)
            ->subject($isClient 
                ? 'Consultation Payment Confirmed' 
                : 'Consultation Payment Received')
            ->greeting($isClient ? "Hello {$client->name}," : "Hello {$company->company_name},")
            ->line($isClient
                ? "Your payment for the consultation with {$company->company_name} has been confirmed."
                : "Payment has been received for your consultation with {$client->name}.");

        // Add consultation details in a formatted way
        $details = "**Consultation Details:**\n\n";
        $details .= "- **Date & Time:** " . $this->consultation->scheduled_at->format('F j, Y \a\t g:i A') . "\n";
        $details .= "- **Duration:** {$this->consultation->duration_minutes} minutes\n";
        $details .= "- **Amount Paid:** ₦" . number_format($this->consultation->price, 2);

        $message->line($details);

        if ($this->consultation->meeting_link) {
            $message->line("**Meeting Link:**")
                ->action('Join Meeting', $this->consultation->meeting_link);
        }

        $message->action('View Consultation', url('/consultations/' . $this->consultation->id))
            ->line('Thank you for using our platform!');

        return $message;
    }
}
