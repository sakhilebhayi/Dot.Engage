<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Message $message) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sender = $this->message->sender;
        $preview = str($this->message->body)->limit(120)->toString();
        $url = route('chat.show', $this->message->conversation_id);

        return (new MailMessage)
            ->subject('New message from '.$sender->name)
            ->greeting('Hi '.$notifiable->name.'!')
            ->line($sender->name.' sent you a message:')
            ->line("> {$preview}")
            ->action('View Conversation', $url)
            ->line('Reply directly in Dot.Engage.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_message',
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->user_id,
            'sender_name' => $this->message->sender->name,
            'preview' => str($this->message->body)->limit(80)->toString(),
        ];
    }
}
