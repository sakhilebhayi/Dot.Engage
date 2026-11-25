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
 * InfoDot did not give a usable answer. A clear answer also says whether InfoDot has the platform switched on at all
 * (`available`, R-61 contract v2: false for maintenance or unavailable, for every company, whatever it holds).
 *
 * Only a clear answer may ever stop anyone: an outage, a wrong secret or a body we cannot read is "unknown", which
 * the caller treats as carry on. A clear answer is kept for one minute per company, so a switch made at InfoDot takes
 * effect within about a minute, and is also remembered for six hours: while InfoDot cannot be reached the last clear
 * answer is used, so a platform last told "unavailable" stays unavailable and a refusal stays a refusal. With no
 * answer ever, the caller carries on. An unknown answer is kept for sixty seconds, so a failure is asked about, and
 * logged, once a minute.
 */
class CompanyEntitlement
{
    public const HELD = 'held';

    public const NOT_HELD = 'not_held';

    public const UNKNOWN = 'unknown';

    private const ANSWER_SECONDS = 60;

    /** How long the last clear answer is used when InfoDot cannot be reached. */
    private const LAST_KNOWN_SECONDS = 21600;

    private const FAILURE_SECONDS = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    /**
     * @return array{state: string, status: ?string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}
     */
    public function for(Team $team): array
    {
        $uuid = (string) $team->infodot_team_uuid;

        if ($uuid === '') {
            return $this->none();
        }

        $key = 'infodot:entitlement:'.$this->platform().':'.$uuid;

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && isset($cached['state'])) {
            return $cached;
        }

        $answer = $this->ask($uuid);

        try {
            if ($answer['state'] !== self::UNKNOWN) {
                Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_SECONDS);
            } else {
                $last = Cache::get($key.':last');

                if (is_array($last) && is_array($last['answer'] ?? null) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_SECONDS) {
                    $answer = $last['answer'];
                }
            }

            Cache::put($key, $answer, $answer['state'] === self::UNKNOWN ? self::FAILURE_SECONDS : self::ANSWER_SECONDS);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

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

    /** InfoDot's read endpoint: ENGAGE_ENTITLEMENT_URL when set ({uuid} is replaced), else the host this app redeems sign-in codes at. */
    private function url(string $uuid): ?string
    {
        $configured = config('ecosystem.entitlement.url');

        if (is_string($configured) && $configured !== '') {
            return str_replace('{uuid}', rawurlencode($uuid), $configured);
        }

        $redeem = (string) config('ecosystem.infodot.redeem_url');

        return $redeem === '' ? null : Str::beforeLast($redeem, '/').'/companies/'.rawurlencode($uuid).'/platforms';
    }

    /**
     * @return array{state: string, status: ?string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}
     */
    private function ask(string $uuid): array
    {
        $secret = (string) config('ecosystem.infodot.secret');
        $url = $this->url($uuid);

        if ($secret === '' || $url === null) {
            return $this->unknown('service secret or InfoDot address not set');
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->timeout((int) config('ecosystem.entitlement.timeout', 3))
                ->get($url, ['platform' => $this->platform()]);
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
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
                    'features' => self::features($row['features'] ?? null),
                ];
            }
        }

        return $this->unknown('own row missing');
    }

    /**
     * The feature switches in the platform's own row (R-61), kept as key => state and message. Anything malformed is
     * ignored, and a feature that is not listed is available.
     *
     * @return array<string, array{state: string, message: ?string}>
     */
    private static function features(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $key => $row) {
            if (is_string($key) && is_array($row) && is_string($row['state'] ?? null)) {
                $out[$key] = ['state' => $row['state'], 'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null];
            }
        }

        return $out;
    }

    /**
     * @return array{state: string, status: ?string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}
     */
    private function none(): array
    {
        return ['state' => self::UNKNOWN, 'status' => null, 'available' => true, 'message' => null, 'features' => []];
    }

    /**
     * @return array{state: string, status: ?string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}
     */
    private function unknown(string $why): array
    {
        // Once per cache window, because the answer is cached for that window. Never the secret, never a person.
        Log::warning('InfoDot entitlement check gave no answer; carrying on.', ['platform' => $this->platform(), 'reason' => $why]);

        return $this->none();
    }
}
