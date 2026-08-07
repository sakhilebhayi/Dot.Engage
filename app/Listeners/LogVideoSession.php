<?php

namespace App\Listeners;

use App\Events\VideoSessionEnded;
use App\Jobs\ArchiveVideoSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogVideoSession implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(VideoSessionEnded $event): void
    {
        $session = $event->session;

        // Persist the ended_at timestamp if not already set.
        if ($session->ended_at === null) {
            $session->update([
                'ended_at' => now(),
                'status' => 'ended',
            ]);
        }

        // Dispatch the archival job to run asynchronously.
        ArchiveVideoSession::dispatch($session);
    }
}
