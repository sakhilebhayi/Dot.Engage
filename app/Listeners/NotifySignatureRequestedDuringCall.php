<?php

namespace App\Listeners;

use App\Events\SignatureRequestedDuringCall;
use App\Notifications\SignatureRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifySignatureRequestedDuringCall implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SignatureRequestedDuringCall $event): void
    {
        $team = $event->session->team;

        // Notify every team member watching the call except the person
        // who just signed -- they don't need to be told to sign.
        $recipients = $team->allUsers()->reject(
            fn ($user) => $user->id === $event->signedBy->id
        );

        foreach ($recipients as $recipient) {
            $recipient->notify(
                new SignatureRequestedNotification($event->session, $event->contract)
            );
        }
    }
}
