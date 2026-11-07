<?php

namespace App\Services\Engage;

/**
 * The STUN and TURN servers handed to a browser for a call. STUN is enough for most networks; TURN relays the media
 * for the rest. With ENGAGE_TURN_SECRET the credentials are minted per request and expire (coturn "use-auth-secret").
 */
class IceConfig
{
    /** @return array{servers: list<array<string, mixed>>, relay: bool} */
    public function forUser(string $identity): array
    {
        $servers = [];
        $stun = config('engage.rtc.stun_urls', []);
        if ($stun) {
            $servers[] = ['urls' => array_values($stun)];
        }

        $turn = config('engage.rtc.turn_urls', []);
        $relay = false;
        if ($turn) {
            if ($secret = config('engage.rtc.turn_secret')) {
                $username = (time() + (int) config('engage.rtc.turn_ttl')).':'.preg_replace('/[^A-Za-z0-9_.-]/', '', $identity);
                $servers[] = ['urls' => array_values($turn), 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, (string) $secret, true))];
                $relay = true;
            } elseif (config('engage.rtc.turn_username') && config('engage.rtc.turn_credential')) {
                $servers[] = ['urls' => array_values($turn), 'username' => config('engage.rtc.turn_username'), 'credential' => config('engage.rtc.turn_credential')];
                $relay = true;
            }
        }

        return ['servers' => $servers, 'relay' => $relay];
    }
}
