<?php

namespace App\Events\Concerns;

/**
 * Skip broadcasting entirely on the "null" connection.
 *
 * Without this, every ShouldBroadcast event still writes a BroadcastEvent
 * job to the queue table (a SQLite write) for the cron worker to pick up and
 * hand to a driver that discards it.
 */
trait BroadcastsOnlyWhenEnabled
{
    public function broadcastWhen(): bool
    {
        // env('BROADCAST_CONNECTION') = "null" is read as PHP null, which the
        // broadcast manager treats as the "null" connection.
        return ! in_array((string) config('broadcasting.default'), ['', 'null'], true);
    }
}
