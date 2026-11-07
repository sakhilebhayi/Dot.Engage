<?php

namespace App\Notifications;

use App\Models\Engagement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The one-time code that proves an invited person owns the address. Sent at once, not queued, because they are waiting for it. */
class VerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Engagement $engagement, public readonly string $code, public readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Dot.Engage code: '.$this->code)
            ->greeting('Dot.Engage')
            ->line('Use this code to open **'.$this->engagement->title.'** ('.$this->engagement->reference.'):')
            ->line('# '.$this->code)
            ->line('It works once and expires in '.$this->minutes.' minutes. If you did not ask for it, ignore this message; nobody can open the engagement without it.');
    }
}
