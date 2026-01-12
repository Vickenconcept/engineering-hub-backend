<?php

namespace App\Notifications;

use App\Models\Consultation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ConsultationPaidNotification extends Notification implements ShouldQueue
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
            ? 'Consultation Payment Confirmed' 
            : 'Consultation Payment Received';

        Log::info('Sending ConsultationPaidNotification email', [
            'user_id' => $notifiable->id,
            'user_email' => $notifiable->email,
            'consultation_id' => $this->consultation->id,
            'subject' => $subject,
            'is_client' => $isClient,
        ]);

        $message = (new MailMessage)
            ->subject($subject)
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

    public function toArray($notifiable): array
    {
        $isClient = $notifiable->id === $this->consultation->client_id;
        $company = $this->consultation->company;
        $client = $this->consultation->client;

        return [
            'type' => 'consultation_paid',
            'title' => $isClient 
                ? 'Consultation Payment Confirmed' 
                : 'Consultation Payment Received',
            'message' => $isClient
                ? "Your payment for the consultation with {$company->company_name} has been confirmed."
                : "Payment has been received for your consultation with {$client->name}.",
            'data' => [
                'consultation_id' => $this->consultation->id,
                'consultation_date' => $this->consultation->scheduled_at->format('F j, Y \a\t g:i A'),
                'amount' => $this->consultation->price,
                'meeting_link' => $this->consultation->meeting_link,
                'action_url' => '/consultations/' . $this->consultation->id,
            ],
        ];
    }
}
