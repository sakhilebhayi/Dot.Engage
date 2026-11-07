<?php

namespace App\Notifications;

use App\Models\Engagement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification for everything that happens in an engagement. The email names the engagement and what
 * happened, and links to it; it never carries clause text, comments or any document content.
 */
class EngagementNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly Engagement $engagement,
        public readonly string $type,
        public readonly string $message,
        public readonly string $url,
        public readonly ?string $actionLabel = null,
        public readonly bool $emailOnly = false,
    ) {}

    public function via(object $notifiable): array
    {
        // Outside parties have no account: a mail-only route notifiable.
        if ($this->emailOnly || ! method_exists($notifiable, 'notifications')) {
            return ['mail'];
        }

        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->message.' · '.$this->engagement->reference)
            ->greeting('Dot.Engage')
            ->line($this->message)
            ->line('Engagement: **'.$this->engagement->title.'** ('.$this->engagement->reference.')')
            ->action($this->actionLabel ?? 'Open the engagement', $this->url)
            ->line('For your security, the link opens a page that asks for a one-time code sent to this address. If you were not expecting this, you can ignore the message.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'engagement_id' => $this->engagement->id,
            'engagement_reference' => $this->engagement->reference,
            'engagement_title' => $this->engagement->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
