<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

/**
 * The header carries the shared Dot UI (dot-ui): the search pill and the platform switcher. Presentation changed; the
 * search form, its route, parameter, permissions and results did not, and the switcher only ever shows what InfoDot
 * says the CURRENT company holds, linking through InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'engage', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['engage', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['engage' => 'Dot.Engage', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'engage', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    /** @return list<string> the references of the engagements the page LISTS (not the query it echoes back) */
    private function listedReferences($response): array
    {
        preg_match_all('/<span class="row-end fig">([^<]+)<\/span>/', $response->getContent(), $m);

        return $m[1];
    }

    // ── The search pill ──────────────────────────────────────────────────────

    public function test_the_header_search_keeps_its_form_route_and_parameter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('engagements.index', ['q' => 'Supply']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form role="search" method="get"\s+action="([^"]+)"/', $html, $form), 'a GET search form');
        $this->assertSame(route('engagements.index'), html_entity_decode($form[1]));
        $this->assertMatchesRegularExpression('/<input[^>]*\btype="search"[^>]*\bname="q"[^>]*\bvalue="Supply"/', $html, 'same parameter, current value kept');
        $this->assertStringContainsString('placeholder="Find an engagement"', $html);
        $this->assertStringContainsString('class="dsr-input"', $html, 'the shared pill');
        $this->assertStringNotContainsString('<form class="search"', $html, 'the old search form is gone');
        $this->assertSame(1, substr_count($html, 'role="search"'), 'one search landmark in the header');
    }

    public function test_the_header_search_submits_nothing_extra_and_shows_no_invented_shortcut(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->getContent();

        $this->assertStringNotContainsString('dsr-kbd', $html, 'no Ctrl K hint: Engage has no such shortcut');
        $this->assertStringNotContainsString('data-dsr-shortcut', $html);
        $this->assertStringContainsString('dsr dsr--nohint', $html, 'no hint: the pill reserves only its circle, not the 104px for a hint');
        $this->assertStringContainsString('id="dsr-q"', $html, 'the input id is derived from its name, the same on every render');
    }

    public function test_a_guest_sees_no_search_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    // ── Search results: the logic and the permissions are unchanged ─────────

    public function test_search_finds_by_title_reference_and_party_and_never_shows_another_business(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME)),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX)),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');
        $mine = $this->makeEngagement($acme, 'Borrowdale Traders');
        $theirs = $this->makeEngagement($globex, 'Zeta Holdings');

        // Same title in both businesses: only my own comes back.
        $this->assertSame([$mine->reference], $this->listedReferences($this->actingAs($acme)->get(route('engagements.index', ['q' => 'Supply agreement']))->assertOk()));
        $this->assertSame([$mine->reference], $this->listedReferences($this->actingAs($acme)->get(route('engagements.index', ['q' => $mine->reference]))->assertOk()));
        $this->assertSame([$mine->reference], $this->listedReferences($this->actingAs($acme)->get(route('engagements.index', ['q' => 'Borrowdale']))->assertOk()));

        // The other business's party and reference find nothing for me.
        $zeta = $this->actingAs($acme)->get(route('engagements.index', ['q' => 'Zeta']))->assertOk()->assertSee('Nothing matches');
        $this->assertSame([], $this->listedReferences($zeta));
        $this->assertSame([], $this->listedReferences($this->actingAs($acme)->get(route('engagements.index', ['q' => $theirs->reference]))->assertOk()));

        // And the other way round.
        $this->assertSame([$theirs->reference], $this->listedReferences($this->actingAs($globex)->get(route('engagements.index', ['q' => 'Zeta']))->assertOk()));
        $this->assertSame([], $this->listedReferences($this->actingAs($globex)->get(route('engagements.index', ['q' => 'Borrowdale']))->assertOk()));
    }

    public function test_search_still_needs_a_signed_in_person_with_a_business(): void
    {
        $this->get(route('engagements.index', ['q' => 'Supply']))->assertRedirect(route('login'));
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get('/engagements')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="engage"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        // Current platform: a marker, not a link.
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Engage', $here[0]);

        // Held platforms are links through InfoDot, with this company; InfoDot itself goes home.
        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        // Not held: not a tile, only in the "not switched on" list that points at InfoDot's explanation.
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);
        $this->assertStringContainsString('Not switched on (3)', $panel);

        // Never a token, never a secret, never straight to a destination host.
        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'engage');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['engage', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['engage', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get('/engagements')->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('opened through InfoDot', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringContainsString('/go/files', $panel, 'the core set still leaves through InfoDot, which decides again');
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/engagements')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    public function test_the_landing_no_longer_has_the_two_link_back_nav_because_the_switcher_replaces_it(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('land-eco', $html);
        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('href="https://infodot.test/home"', $html, 'InfoDot stays one click away, from the switcher');
        $this->assertStringContainsString('See all platforms', $html);
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        // Every relative url() in the shared CSS lands on a file that is really there (fonts, arrow mask, stencil face).
        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        // Logos: this site's own copies, so strict img-src works.
        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The policy gained no origin: only what it listed before.
        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match_all('#https?://[^\s;]+#', $policy, $origins);
        $this->assertEqualsCanonicalizing(['https://ui-avatars.com', 'https://infodot.co.za', 'https://*.infodot.co.za'], $origins[0]);
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/engagements')->getContent();

        $this->assertSame(1, preg_match('/<div class="topbar-search">.*?<\/div>\s*<\/div>/s', $html, $pill));
        foreach ([$pill[0], $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }

    public function test_the_chat_list_filter_is_the_shared_compact_filter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('chat.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*>/', $html);
        $this->assertStringContainsString('aria-label="Search conversations"', $html);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $html);
    }
}
