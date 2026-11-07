<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SyncNeedsAttention extends Notification
{
    use Queueable;

    public function __construct(public readonly string $summary) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sheets Sync needs attention')
            ->line($this->summary)
            ->action('Open the admin panel', url('/admin'));
    }
}
