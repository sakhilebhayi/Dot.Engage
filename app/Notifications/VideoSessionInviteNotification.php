<?php

namespace App\Notifications;

use App\Models\VideoSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VideoSessionInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly VideoSession $session) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $initiator = $this->session->initiator;
        $url = route('video.room', ['room' => $this->session->room_id]);

        $subject = $initiator->name.' has started a video session';
        if ($this->session->contract_id) {
            $subject .= ' — document signing required';
        }

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.'!')
            ->line($initiator->name.' has started a video session and invited you to join.')
            ->when($this->session->contract_id, fn ($m) => $m->line('A contract is attached for signing during the call.'))
            ->action('Join Now', $url)
            ->line('The session is live. Click above to join.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'video_session_invite',
            'session_id' => $this->session->id,
            'room_id' => $this->session->room_id,
            'initiator_id' => $this->session->initiated_by,
            'initiator_name' => $this->session->initiator->name,
            'contract_id' => $this->session->contract_id,
        ];
    }
}
