<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Notifications\NewMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendMessageNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $conversation = $message->conversation;
        $senderId = $message->user_id;

        // Notify every participant in the conversation except the sender.
        $conversation->participants()
            ->where('users.id', '!=', $senderId)
            ->each(function ($user) use ($message) {
                $user->notify(new NewMessageNotification($message));
            });
    }
}
