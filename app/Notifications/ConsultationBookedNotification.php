<?php

namespace App\Notifications;

use App\Models\Consultation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsultationBookedNotification extends Notification implements ShouldQueue
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

        return (new MailMessage)
            ->subject($isClient 
                ? 'Consultation Booked Successfully' 
                : 'New Consultation Request Received')
            ->greeting($isClient ? "Hello {$client->name}," : "Hello {$company->company_name},")
            ->line($isClient
                ? "Your consultation with {$company->company_name} has been booked successfully."
                : "You have received a new consultation request from {$client->name}.")
            ->line("**Consultation Details:**")
            ->line("- **Date & Time:** " . $this->consultation->scheduled_at->format('F j, Y \a\t g:i A'))
            ->line("- **Duration:** {$this->consultation->duration_minutes} minutes")
            ->line("- **Price:** ₦" . number_format($this->consultation->price, 2))
            ->line($isClient
                ? "Please proceed to make payment to confirm your consultation."
                : "The client will make payment to confirm the consultation.")
            ->action($isClient ? 'Make Payment' : 'View Consultation', url('/consultations/' . $this->consultation->id))
            ->line('Thank you for using our platform!');
    }
}
