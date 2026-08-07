<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\VideoSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignatureRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly VideoSession $session,
        public readonly Contract $contract,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('video.room', ['room' => $this->session->room_id]);

        return (new MailMessage)
            ->subject('Your signature is requested on '.$this->contract->title)
            ->greeting('Hi '.$notifiable->name.'!')
            ->line('You have been asked to sign the contract **'.$this->contract->title.'** during an active video session.')
            ->action('Join Session & Sign', $url)
            ->line('The session is live — please join as soon as possible.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'signature_requested',
            'session_id' => $this->session->id,
            'room_id' => $this->session->room_id,
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
        ];
    }
}
