<?php

declare(strict_types=1);

namespace Dot\Ui;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The platform switcher's view model (R-51).
 *
 * Asks InfoDot which platforms the CURRENT company holds, for the platform that is asking, and turns the answer into
 * something a template can print without deciding anything. It never throws: every failure is the `degraded` state.
 *
 *   ok        InfoDot answered and the answer was readable. Only rows with `entitled === true` are links.
 *   degraded  InfoDot did not give a usable answer (outage, 401/403/404/429/5xx, timeout, unreadable body, no secret).
 *             Shows the static core set as "open via InfoDot" tiles and a visible "could not check" notice. Never says
 *             a platform is active. A stale tile cannot grant anything: every tile goes through InfoDot's /go, where
 *             InfoDot decides again for the person.
 *   personal  A personal workspace (or no company at all): one sentence, no list, nothing asked.
 *
 * The answer is about the COMPANY only (InfoDot's read endpoint says nothing about the person), so a tile means "the
 * company has it", not "you may open it". InfoDot's /go makes the per-person decision and explains a refusal.
 *
 * Nothing here trusts the browser: the platform key, company uuid and secret come from the server. The view model
 * carries no secret and no token; links are `{infodot}/go/<key>?company=<uuid>` (a company id is an identifier, not a
 * credential), and InfoDot validates membership.
 */
final class PlatformSwitcher
{
    public const STATE_OK = 'ok';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_PERSONAL = 'personal';

    public const ANSWER_SECONDS = 600;

    public const FAILURE_SECONDS = 60;

    public const NOTICE = 'Could not check your platforms just now';

    public const PERSONAL_MESSAGE = 'You are in your personal workspace, which has no company platforms to switch between. Switch to a company in InfoDot to see what it has.';

    /** The free core every company holds. Used only when InfoDot cannot be asked, and only to route via InfoDot. */
    public const CORE = ['infodot', 'files', 'docs', 'tasks', 'finance'];

    /** Platforms with their own sign-in and no InfoDot receiver: opened by a plain link, nothing is handed over. */
    public const PLAIN = ['mines', 'charts'];

    /** Bridge services people never open. Dropped if an answer ever lists them. */
    public const INTERNAL = ['memory', 'dopemine', 'brain', 'assets'];

    /** Names for the degraded list only (the live answer carries its own names). */
    private const CORE_NAMES = [
        'infodot' => 'InfoDot',
        'files' => 'Dot.Files',
        'docs' => 'Dot.docs',
        'tasks' => 'Dot.Tasks',
        'finance' => 'Dot.Finance',
    ];

    /**
     * Display grouping, from InfoDot's catalogue: each platform under its FIRST category, then the plate InfoDot
     * stencils for it. Presentation only, never access. A key not listed goes under "More platforms".
     *
     * @var array<string, array{0: string, 1: string, 2: string}> category => [code, tone, name]
     */
    private const CATEGORIES = [
        'business_management' => ['BSNS', 'people', 'Business Management'],
        'finance_administration' => ['FINA', 'files', 'Finance and Administration'],
        'productivity_collaboration' => ['WORK', 'knowledge', 'Productivity and Collaboration'],
        'sales_commerce' => ['SALE', 'platforms', 'Sales and Commerce'],
        'marketing_design' => ['MRKT', 'documents', 'Marketing and Design'],
        'data_intelligence' => ['DATA', 'analytics', 'Data and Intelligence'],
        'human_resources' => ['CREW', 'people', 'Human Resources'],
        'learning_development' => ['LEARN', 'documents', 'Learning and Development'],
        'operations_industry' => ['OPER', 'files', 'Operations and Industry'],
        'automation_ai' => ['AUTO', 'analytics', 'Automation and AI'],
    ];

    private const PRIMARY_CATEGORY = [
        'infodot' => 'business_management',
        'engage' => 'business_management',
        'projects' => 'business_management',
        'files' => 'productivity_collaboration',
        'docs' => 'productivity_collaboration',
        'tasks' => 'productivity_collaboration',
        'forms' => 'productivity_collaboration',
        'press' => 'productivity_collaboration',
        'sheet' => 'productivity_collaboration',
        'finance' => 'finance_administration',
        'agents' => 'automation_ai',
        'notify' => 'automation_ai',
        'analytics' => 'data_intelligence',
        'charts' => 'data_intelligence',
        'auction' => 'sales_commerce',
        'emall' => 'sales_commerce',
        'pulse' => 'sales_commerce',
        'central' => 'operations_industry',
        'ehail' => 'operations_industry',
        'mines' => 'operations_industry',
        'design' => 'marketing_design',
        'hr' => 'human_resources',
        'tutor' => 'learning_development',
    ];

    private readonly string $infodot;

    private readonly Http $http;

    /**
     * @param  string  $platform  This platform's own key, as InfoDot knows it (config `ecosystem.infodot.platform`).
     * @param  string  $infodotUrl  InfoDot's base URL (config `ecosystem.platforms.infodot.url`).
     * @param  string|null  $secret  This platform's service secret (config `ecosystem.infodot.secret`).
     * @param  string|null  $companiesUrl  Where the read goes, `{companies_url}/{uuid}/platforms`. Blank: `{infodot}/api/ecosystem/companies`.
     */
    public function __construct(
        private readonly string $platform,
        string $infodotUrl,
        #[\SensitiveParameter] private readonly ?string $secret,
        private readonly Cache $cache,
        ?Http $http = null,
        private readonly ?LoggerInterface $log = null,
        private readonly ?string $companiesUrl = null,
        private readonly int $timeout = 3,
        private readonly int $connectTimeout = 2,
    ) {
        $this->infodot = rtrim($infodotUrl, '/');
        $this->http = $http ?? new Http();
    }

    /**
     * Built from the keys every Dot platform already has: `ecosystem.infodot.{platform,secret,redeem_url,companies_url,
     * timeout}` and `ecosystem.platforms.infodot.url`. Laravel only; the constructor is the framework-free door.
     * `$platform` overrides `ecosystem.infodot.platform` (the component's `platform` prop).
     */
    public static function fromConfig(?string $platform = null): self
    {
        $config = static fn (string $key, mixed $default = null): mixed => config($key, $default);

        $redeem = (string) $config('ecosystem.infodot.redeem_url', '');
        $base = (string) ($config('ecosystem.platforms.infodot.url') ?: self::origin($redeem) ?: 'https://infodot.co.za');
        $companies = (string) $config('ecosystem.infodot.companies_url', '');
        $secret = $config('ecosystem.infodot.secret');

        return new self(
            platform: $platform !== null && $platform !== '' ? $platform : (string) $config('ecosystem.infodot.platform', ''),
            infodotUrl: $base,
            secret: is_string($secret) ? $secret : null,
            cache: app('cache')->store(),
            http: app(Http::class),
            log: app()->bound(LoggerInterface::class) ? app(LoggerInterface::class) : null,
            companiesUrl: $companies !== '' ? $companies : ($redeem !== '' ? self::beforeLast($redeem, '/').'/companies' : null),
            timeout: max(1, (int) $config('ecosystem.infodot.switcher_timeout', 3)),
        );
    }

    /**
     * @return array{
     *   state: string, current: string, company: ?string, infodot_url: string, platforms_url: string,
     *   notice: ?string, message: ?string, count: int,
     *   groups: list<array{code: string, tone: string, name: string, tiles: list<array<string, mixed>>}>,
     *   more: list<array{key: string, name: string, href: string}>
     * }
     */
    public function for(?string $company): array
    {
        $company = $this->cleanUuid($company);

        if ($this->platform === '' || $company === null) {
            return $this->personal($company);
        }

        $answer = $this->answer($company);

        if ($answer['ok'] !== true) {
            return $this->degraded($company);
        }

        if ($answer['personal'] === true) {
            return $this->personal($company);
        }

        return $this->ready($company, $answer['rows']);
    }

    /** Where a person sees and manages what their company has. */
    public function platformsUrl(): string
    {
        return $this->infodot.'/platforms';
    }

    /** `{infodot}/go/<key>?company=<uuid>`: InfoDot decides, mints the single-use handoff and posts it on. */
    public function goUrl(string $key, ?string $company): string
    {
        $url = $this->infodot.'/go/'.rawurlencode($key);

        return $company !== null ? $url.'?company='.rawurlencode($company) : $url;
    }

    // ---------------------------------------------------------------------------------------------------- answers

    /**
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool}>}
     */
    private function answer(string $company): array
    {
        $key = $this->cacheKey($company);

        try {
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached) && array_key_exists('ok', $cached)) {
            return $cached + ['personal' => false, 'rows' => []];
        }

        $answer = $this->ask($company);

        try {
            $this->cache->put($key, $answer, $answer['ok'] ? self::ANSWER_SECONDS : self::FAILURE_SECONDS);
        } catch (Throwable) {
            // An unwritable cache must not take the page down. The next render asks again.
        }

        return $answer;
    }

    /**
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool}>}
     */
    private function ask(string $company): array
    {
        $secret = (string) $this->secret;

        if ($secret === '') {
            return $this->failed('service secret not set');
        }

        $endpoint = rtrim($this->companiesUrl ?: $this->infodot.'/api/ecosystem/companies', '/').'/'.rawurlencode($company).'/platforms';

        try {
            $response = $this->http->withToken($secret)
                ->acceptJson()
                ->withHeaders(['X-Dot-Platform' => $this->platform])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get($endpoint, ['platform' => $this->platform]);
        } catch (Throwable $e) {
            return $this->failed('unreachable ('.(new \ReflectionClass($e))->getShortName().')');
        }

        if (! $response->successful()) {
            return $this->failed('status '.$response->status());
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            $body = null;
        }

        if (! is_array($body) || ! is_array($body['platforms'] ?? null) || ! is_array($body['company'] ?? null)
            || ! is_string($body['company']['uuid'] ?? null) || strtolower($body['company']['uuid']) !== $company) {
            return $this->failed('unreadable answer');
        }

        $rows = [];

        foreach ($body['platforms'] as $row) {
            if (! is_array($row) || ! is_string($row['key'] ?? null) || ! preg_match('/^[a-z0-9_-]{1,40}$/', $row['key'])
                || ! is_string($row['name'] ?? null) || trim($row['name']) === '' || ! is_bool($row['entitled'] ?? null)) {
                continue;
            }

            $rows[] = [
                'key' => $row['key'],
                'name' => mb_substr(trim($row['name']), 0, 60),
                'entitled' => $row['entitled'],
                'plain' => ($row['status'] ?? null) === 'managed_in_platform',
            ];
        }

        if ($rows === [] && ($body['company']['personal'] ?? false) !== true) {
            return $this->failed('answer lists no platforms');
        }

        return ['ok' => true, 'personal' => ($body['company']['personal'] ?? false) === true, 'rows' => $rows];
    }

    /**
     * @return array{ok: bool, personal: bool, rows: list<array{key: string, name: string, entitled: bool}>}
     */
    private function failed(string $why): array
    {
        // Once per cache window, since the failure is cached for that window. Never the secret, never a person.
        try {
            $this->log?->warning('InfoDot platform list gave no answer; showing the core set via InfoDot.', ['platform' => $this->platform, 'reason' => $why]);
        } catch (Throwable) {
        }

        return ['ok' => false, 'personal' => false, 'rows' => []];
    }

    // ----------------------------------------------------------------------------------------------- view models

    /**
     * @param  list<array{key: string, name: string, entitled: bool, plain?: bool}>  $rows
     */
    private function ready(string $company, array $rows): array
    {
        $tiles = [];
        $more = [];
        $seen = [];

        foreach ($rows as $row) {
            if (in_array($row['key'], self::INTERNAL, true) || isset($seen[$row['key']])) {
                continue;
            }
            $seen[$row['key']] = true;

            $current = $row['key'] === $this->platform;

            if ($row['entitled'] === true || $current) {
                $tiles[] = $this->tile($row['key'], $row['name'], $company, $current, ($row['plain'] ?? false) === true);
            } else {
                $more[] = ['key' => $row['key'], 'name' => $row['name'], 'href' => $this->infodot.'/platforms/'.rawurlencode($row['key'])];
            }
        }

        // This platform missing from the answer is not a reason to hide where the person is.
        if (! isset($seen[$this->platform]) && ! in_array($this->platform, self::INTERNAL, true)) {
            array_unshift($tiles, $this->tile($this->platform, self::CORE_NAMES[$this->platform] ?? $this->platform, $company, true, false));
        }

        return $this->model(self::STATE_OK, $company, $tiles, $more, null, null);
    }

    private function degraded(?string $company): array
    {
        $tiles = [];

        foreach (self::CORE as $key) {
            $tiles[] = $this->tile($key, self::CORE_NAMES[$key], $company, $key === $this->platform, false);
        }

        return $this->model(self::STATE_DEGRADED, $company, $tiles, [], self::NOTICE, null);
    }

    private function personal(?string $company): array
    {
        return $this->model(self::STATE_PERSONAL, $company, [], [], null, self::PERSONAL_MESSAGE);
    }

    /**
     * @return array{key: string, name: string, href: ?string, current: bool, plain: bool, via_infodot: bool, logo: ?string}
     */
    private function tile(string $key, string $name, ?string $company, bool $current, bool $plainFromAnswer): array
    {
        $plain = in_array($key, self::PLAIN, true) || $plainFromAnswer;
        $home = $key === 'infodot';

        // A tile for the current platform is a marker, not a link. InfoDot's own tile goes to its home, no handoff.
        $href = match (true) {
            $current => null,
            $home => $this->infodot.'/home',
            $plain => $this->goUrl($key, null),
            default => $this->goUrl($key, $company),
        };

        return [
            'key' => $key,
            'name' => $name,
            'href' => $href,
            'current' => $current,
            'plain' => $plain,
            'via_infodot' => ! $plain && ! $home,
            'logo' => PlatformLogos::file($key),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tiles
     * @param  list<array{key: string, name: string, href: string}>  $more
     */
    private function model(string $state, ?string $company, array $tiles, array $more, ?string $notice, ?string $message): array
    {
        $groups = [];

        foreach ($tiles as $tile) {
            $category = self::PRIMARY_CATEGORY[$tile['key']] ?? null;
            [$code, $tone, $name] = $category !== null ? self::CATEGORIES[$category] : ['MORE', 'platforms', 'More platforms'];
            $groups[$category ?? 'more'] ??= ['code' => $code, 'tone' => $tone, 'name' => $name, 'tiles' => []];
            $groups[$category ?? 'more']['tiles'][] = $tile;
        }

        // InfoDot's own order: the catalogue categories as listed, anything unplaced last.
        $ordered = [];
        foreach ([...array_keys(self::CATEGORIES), 'more'] as $category) {
            if (isset($groups[$category])) {
                $ordered[] = $groups[$category];
            }
        }

        return [
            'state' => $state,
            'current' => $this->platform,
            'company' => $company,
            'infodot_url' => $this->infodot,
            'platforms_url' => $this->platformsUrl(),
            'notice' => $notice,
            'message' => $message,
            'count' => count($tiles),
            'groups' => $ordered,
            'more' => $more,
        ];
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    private function cacheKey(string $company): string
    {
        return 'infodot:platforms:'.$this->platform.':'.$company;
    }

    private function cleanUuid(?string $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1 ? $value : null;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';
    }

    private static function beforeLast(string $subject, string $search): string
    {
        $pos = strrpos($subject, $search);

        return $pos === false ? $subject : substr($subject, 0, $pos);
    }
}
