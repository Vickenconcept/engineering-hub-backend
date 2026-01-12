<?php

namespace App\Notifications;

use App\Models\Consultation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ConsultationBookedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Consultation $consultation
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $isClient = $notifiable->id === $this->consultation->client_id;
        $company = $this->consultation->company;
        $client = $this->consultation->client;

        $subject = $isClient 
            ? 'Consultation Booked Successfully' 
            : 'New Consultation Request Received';

        Log::info('Sending ConsultationBookedNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'consultation_id' => $this->consultation->id,
            'subject' => $subject,
            'is_client' => $isClient,
        ]);

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

    public function toDatabase($notifiable): array
    {
        $isClient = $notifiable->id === $this->consultation->client_id;
        $company = $this->consultation->company;
        $client = $this->consultation->client;

        return [
            'type' => 'consultation_booked',
            'consultation_id' => $this->consultation->id,
            'project_id' => null,
            'title' => $isClient 
                ? 'Consultation Booked Successfully' 
                : 'New Consultation Request Received',
            'message' => $isClient
                ? "Your consultation with {$company->company_name} has been booked successfully."
                : "You have received a new consultation request from {$client->name}.",
            'action_url' => url('/consultations/' . $this->consultation->id),
            'icon' => 'calendar',
            'color' => 'info',
            'data' => [
                'consultation_id' => $this->consultation->id,
                'consultation_date' => $this->consultation->scheduled_at->format('F j, Y \a\t g:i A'),
                'price' => $this->consultation->price,
                'action_url' => url('/consultations/' . $this->consultation->id),
            ],
        ];
    }
}
