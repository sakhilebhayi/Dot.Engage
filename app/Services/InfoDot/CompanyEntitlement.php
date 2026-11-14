<?php

namespace App\Services\InfoDot;

use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50), for a person who is already signed in.
 *
 * Three answers, never two: the company holds it, the company does not (with InfoDot's status for the wording), or
 * InfoDot did not give a usable answer. Only a clear "does not hold it" may ever stop anyone: an outage, a wrong
 * secret or a body we cannot read is "unknown", which the caller treats as carry on. A clear answer is kept for ten
 * minutes per company and an unknown one for sixty seconds, so a failure is asked about, and logged, once a minute.
 */
class CompanyEntitlement
{
    public const HELD = 'held';

    public const NOT_HELD = 'not_held';

    public const UNKNOWN = 'unknown';

    private const ANSWER_SECONDS = 600;

    private const FAILURE_SECONDS = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    /**
     * @return array{state: string, status: ?string}
     */
    public function for(Team $team): array
    {
        $uuid = (string) $team->infodot_team_uuid;

        if ($uuid === '') {
            return ['state' => self::UNKNOWN, 'status' => null];
        }

        $key = 'infodot:entitlement:'.$this->platform().':'.$uuid;
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['state'])) {
            return $cached;
        }

        $answer = $this->ask($uuid);
        Cache::put($key, $answer, $answer['state'] === self::UNKNOWN ? self::FAILURE_SECONDS : self::ANSWER_SECONDS);

        return $answer;
    }

    /** Where a person can see or change what their company holds. */
    public function platformsUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.$this->platform();
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'engage');
    }

    /**
     * @return array{state: string, status: ?string}
     */
    private function ask(string $uuid): array
    {
        $secret = (string) config('ecosystem.infodot.secret');
        $redeem = (string) config('ecosystem.infodot.redeem_url');

        if ($secret === '' || $redeem === '') {
            return $this->unknown('service secret or InfoDot address not set');
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->get(Str::beforeLast($redeem, '/').'/companies/'.rawurlencode($uuid).'/platforms', ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return $this->unknown('unreachable ('.class_basename($e).')');
        }

        if (! $response->successful()) {
            return $this->unknown('status '.$response->status());
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['platforms'] ?? null) : null;

        if (! is_array($rows) || ($body['company']['uuid'] ?? null) !== $uuid) {
            return $this->unknown('unreadable answer');
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $this->platform()) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return $this->unknown('own row has no yes or no');
                }

                return [
                    'state' => $row['entitled'] ? self::HELD : self::NOT_HELD,
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
                ];
            }
        }

        return $this->unknown('own row missing');
    }

    /**
     * @return array{state: string, status: ?string}
     */
    private function unknown(string $why): array
    {
        // Once per cache window, because the answer is cached for that window. Never the secret, never a person.
        Log::warning('InfoDot entitlement check gave no answer; carrying on.', ['platform' => $this->platform(), 'reason' => $why]);

        return ['state' => self::UNKNOWN, 'status' => null];
    }
}
