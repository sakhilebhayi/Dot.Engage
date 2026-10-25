<?php

namespace App\Services;

/**
 * Whether live push to the browser is available.
 *
 * Production runs on shared hosting with no websocket server, so the default
 * broadcast connection is "null". Anything that would only work with a
 * push (and needs a polling fallback otherwise) asks here.
 */
class Realtime
{
    /** True when a real broadcaster with a key is configured. */
    public static function active(): bool
    {
        $connection = (string) config('broadcasting.default');

        if (in_array($connection, ['', 'null', 'log'], true)) {
            return false;
        }

        return filled(config("broadcasting.connections.{$connection}.key"));
    }
}
