<?php

namespace Tests\Feature;

use App\Enums\EngagementKind;
use App\Models\Engagement;
use App\Models\Team;
use App\Models\User;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Services\Engage\MeetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

/**
 * R-50: a signed-in person keeps working only while their company holds Dot.Engage in InfoDot. Only a clear "no"
 * refuses; every kind of failure carries on. Outside parties (invitation, signing and meeting links) are never asked
 * about. The answer bodies are the ones InfoDot's CompanyPlatformsEndpointTest asserts.
 */
class CompanyEntitlementTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'engage', 'secret' => $this->bearer, 'timeout' => 2, 'provision' => true, 'entitlement_check' => true,
        ]]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot returns it for this platform asking about one company. */
    private static function body(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'engage',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'engage', 'name' => 'Dot.Engage', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function answer(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function company(User $owner, string $uuid, string $name): Team
    {
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->switchTeam($team);

        // The header's platform switcher (shared Dot UI) reads the same InfoDot endpoint under its own cache key. These
        // tests count and sequence the ENTITLEMENT check's requests, so the switcher's answer is parked as a recent
        // failure: it then asks nothing and the counts below stay the entitlement check's own. The switcher has its own
        // tests (PlatformSwitcherHeaderTest).
        Cache::put('infodot:platforms:engage:'.strtolower($uuid), ['ok' => false, 'personal' => false, 'rows' => []], 3600);

        return $team;
    }

    private function ownerOfAcme(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->company($user, self::ACME, 'Acme Ltd');

        return $user->fresh();
    }

    private function engagementFor(User $user): Engagement
    {
        return app(EngagementService::class)->create($user->currentTeam, $user, 'Supply agreement 2026', EngagementKind::Supplier, 'Annual supply terms', 'Borrowdale Traders');
    }

    public function test_a_company_that_holds_the_platform_carries_on_and_the_caller_names_itself(): void
    {
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, true, 'active'))]);

        $this->actingAs($this->ownerOfAcme())->get('/dashboard')->assertOk();

        Http::assertSent(fn (OutgoingRequest $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms?')
            && $request['platform'] === 'engage'
            && $request->hasHeader('X-Dot-Platform', 'engage')
            && $request->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'A payment is needed before Acme Ltd'],
            'ended' => ['subscription_expired', 'has ended for Acme Ltd'],
            'a status this app does not know' => ['something_new', 'is not switched on for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_does_not_hold_it_gets_the_plain_page_and_no_data(string $status, string $heading): void
    {
        $user = $this->ownerOfAcme();
        $engagement = $this->engagementFor($user);
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, false, $status))]);

        foreach (['/dashboard', '/engagements', '/agreements', '/meetings', '/chat', '/notifications', route('engagements.show', $engagement), route('engagements.discussion', $engagement)] as $page) {
            $this->actingAs($user)->get($page)
                ->assertForbidden()
                ->assertSee($heading)
                ->assertSee('Open Platforms in InfoDot')
                ->assertSee('https://infodot.co.za/platforms/engage', false)
                ->assertDontSee('Supply agreement 2026')
                ->assertDontSee('Borrowdale Traders')
                ->assertDontSee($engagement->reference);
        }
    }

    public function test_nothing_is_changed_while_the_company_does_not_hold_it(): void
    {
        $user = $this->ownerOfAcme();
        $engagement = $this->engagementFor($user);
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, false, 'suspended'))]);

        $this->actingAs($user)->post('/engagements', ['title' => 'Should not exist', 'kind' => 'supplier', 'counterparty' => 'Nobody'])->assertForbidden();
        $this->actingAs($user)->post(route('engagements.cancel', $engagement), ['reason' => 'no'])->assertForbidden();
        $this->actingAs($user)->postJson(route('engagements.post', $engagement), ['body' => 'hello'])->assertForbidden()->assertJsonMissingPath('exception');

        $this->assertSame(1, Engagement::withoutGlobalScopes()->count());
        $this->assertTrue($engagement->fresh()->isOpen());
        $this->assertSame($user->current_team_id, $user->fresh()->current_team_id);
    }

    public static function failures(): array
    {
        return [
            'wrong secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'InfoDot error (500)' => [fn () => Http::response('Server Error', 500)],
            'InfoDot down (503)' => [fn () => Http::response('', 503)],
            'not JSON' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'engage', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'engage', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'own row without a yes or no' => [fn () => Http::response(['platform' => 'engage', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'engage', 'name' => 'Dot.Engage', 'status' => 'suspended', 'entitled' => 'false'],
            ]])],
            'an answer about another company' => [fn () => Http::response(self::body(self::GLOBEX, false, 'suspended'))],
            'timeout or refused connection' => [fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_carries_on_and_is_logged_once_per_window(callable $stub): void
    {
        $user = $this->ownerOfAcme();
        $calls = 0;
        $this->answer([self::url(self::ACME) => function () use (&$calls, $stub) {
            $calls++;

            return $stub();
        }]);
        Log::spy();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Open Platforms in InfoDot');
        $this->actingAs($user)->get('/engagements')->assertOk();

        $this->assertSame(1, $calls, 'asked once, then the failure is remembered for the window');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'entitlement check gave no answer'))->once();
    }

    public function test_a_missing_secret_carries_on_without_calling_infodot(): void
    {
        $user = $this->ownerOfAcme();
        config(['ecosystem.infodot.secret' => null]);
        $this->answer([]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company_for_ten_minutes_and_a_failure_for_one(): void
    {
        $user = $this->ownerOfAcme();
        $this->answer([self::url(self::ACME) => Http::sequence()
            ->push(self::body(self::ACME, true, 'active'))
            ->push('', 503)
            ->push(self::body(self::ACME, false, 'suspended'))]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/engagements')->assertOk();
        Http::assertSentCount(1);

        $this->travel(9)->minutes();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        Http::assertSentCount(1);

        // The ten minutes are up: InfoDot is down, which is cached for a minute and lets the person through.
        $this->travel(2)->minutes();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        Http::assertSentCount(2);

        $this->travel(61)->seconds();
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        Http::assertSentCount(3);
    }

    public function test_the_flag_switched_off_makes_no_call(): void
    {
        $user = $this->ownerOfAcme();
        $engagement = $this->engagementFor($user);
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->answer([]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get(route('engagements.show', $engagement))->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_or_a_team_that_is_not_an_infodot_company_makes_no_call(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->answer([]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $local = Team::forceCreate(['user_id' => $user->id, 'name' => 'Local only', 'personal_team' => false]);
        $user->switchTeam($local);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    public function test_routes_outside_the_signed_in_area_make_no_call(): void
    {
        $user = $this->ownerOfAcme();
        $this->answer([]);

        // Signed out: the public pages, sign-in, the handoff receiver, health checks.
        foreach (['/', '/login', '/up', '/how-signing-works', '/verify', '/cookies', '/terms-of-service', '/privacy-policy', '/auth/ecosystem'] as $page) {
            $this->assertLessThan(400, $this->get($page)->getStatusCode(), $page);
        }
        $this->post('/auth/ecosystem', ['token' => 'x'])->assertRedirect('/login');
        $this->get('/up/scheduler');
        $this->get('/guardian/health')->assertStatus(503);
        $this->postJson('/csp-report', [])->assertSuccessful();

        // Signed in: the same public pages, the account pages, the token API, switching business and signing out.
        foreach (['/', '/how-signing-works', '/verify', '/user/profile', '/teams/'.$user->current_team_id] as $page) {
            $this->actingAs($user)->get($page)->assertSuccessful();
        }
        $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertOk();
        $this->actingAs($user)->post('/logout')->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_an_invited_guest_keeps_their_engagement_signing_and_meeting_links(): void
    {
        $owner = $this->ownerOfAcme();
        $engagement = $this->engagementFor($owner);
        [$participant] = $this->inviteExternal($engagement);
        $participant = $this->verifyParticipant($participant);
        $meeting = app(MeetingService::class)->create($engagement, EngagementAccess::forUser($engagement, $owner), 'Kick-off', null, 30);

        // Even a clear "no" for the host business is never asked for on a guest's request.
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, false, 'suspended'))]);
        $held = [EngagementAccess::SESSION_KEY => [$engagement->id => $participant->id]];

        $this->withSession($held)->get(route('engagements.show', $engagement))->assertOk()->assertDontSee('Open Platforms in InfoDot');
        $this->withSession($held)->get(route('engagements.signing', $engagement))->assertOk();
        $this->withSession($held)->get(route('engagements.meetings', $engagement))->assertOk();
        $this->withSession($held)->get(route('meetings.room', $meeting->room_key))->assertOk();
        $this->get(route('join.show', 'not-a-real-token'))->assertDontSee('Open Platforms in InfoDot');

        Http::assertNothingSent();
    }

    public function test_a_signed_in_person_from_another_business_is_an_outside_party_too(): void
    {
        $owner = $this->ownerOfAcme();
        $engagement = $this->engagementFor($owner);
        [$participant] = $this->inviteExternal($engagement, 'guest@globex.example');
        $participant = $this->verifyParticipant($participant);

        $guest = User::factory()->withPersonalTeam()->create(['email' => 'guest@globex.example']);
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, false, 'suspended'))]);

        $this->actingAs($guest)->withSession([EngagementAccess::SESSION_KEY => [$engagement->id => $participant->id]])
            ->get(route('engagements.show', $engagement))->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_member_reaching_a_meeting_room_of_a_business_that_lost_the_platform_is_refused(): void
    {
        $owner = $this->ownerOfAcme();
        $engagement = $this->engagementFor($owner);
        $meeting = app(MeetingService::class)->create($engagement, EngagementAccess::forUser($engagement, $owner), 'Kick-off', null, 30);
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, false, 'payment_required'))]);

        $this->actingAs($owner)->get(route('meetings.room', $meeting->room_key))->assertForbidden()->assertSee('A payment is needed before Acme Ltd')->assertDontSee('Kick-off');
        $this->actingAs($owner)->getJson(route('meetings.poll', $meeting->room_key))->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_a_second_company_that_holds_the_platform_can_still_be_switched_to(): void
    {
        $user = $this->ownerOfAcme();
        $globex = $this->company($user, self::GLOBEX, 'Globex Ltd');
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $user->switchTeam($acme);

        $this->answer([
            self::url(self::ACME) => Http::response(self::body(self::ACME, false, 'suspended')),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, true, 'active')),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden()->assertSee('Globex Ltd')->assertSee('Or work in another of your businesses');

        $this->actingAs($user)->put('/current-team', ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $user->fresh()->current_team_id);

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_the_refusal_page_offers_one_action_and_no_way_round(): void
    {
        $user = $this->ownerOfAcme();
        $this->answer([self::url(self::ACME) => Http::response(self::body(self::ACME, false, 'suspended'))]);

        $html = $this->actingAs($user)->get('/dashboard')->assertForbidden()->getContent();

        $this->assertSame(1, substr_count($html, 'class="btn"'), 'one primary action');
        $this->assertStringNotContainsString('rest of the Dot Ecosystem', $html);
        $this->assertStringNotContainsString(route('dashboard'), $html);
        $this->assertStringContainsString('Sign out', $html);
        $this->assertStringNotContainsString('Whoops', $html);
        $this->assertTrue(Cache::has('infodot:entitlement:engage:'.self::ACME));
    }

    /** Sign in through InfoDot with an answer that says $personal about the personal workspace (null: no key). */
    private function signInWithPersonal(?bool $personal, bool $withCompany = true): User
    {
        // A fresh guard each time, as a browser starting a new sign-in would have.
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
        auth()->logout();

        $teams = [['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => "Sam's Team", 'personal' => true, 'role' => 'owner']
            + ($personal === null ? [] : ['entitled' => $personal, 'access' => $personal ? null : 'no_personal_access'])];

        if ($withCompany) {
            $teams[] = ['id' => 2, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => 'owner'] + ($personal === null ? [] : ['entitled' => true, 'access' => null]);
        }

        $this->answer([
            self::REDEEM => Http::response([
                'platform' => 'engage',
                'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => 'Sam', 'email' => 'sam@solo.test', 'email_verified' => true],
                'teams' => $teams, 'current_team_id' => 2, 'current_team_uuid' => $withCompany ? self::ACME : null,
            ]),
            self::url(self::ACME) => Http::response(self::body(self::ACME, true, 'active')),
        ]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test'])->assertRedirect(route('dashboard'));

        return User::where('email', 'sam@solo.test')->firstOrFail();
    }

    public function test_a_personal_workspace_infodot_refused_at_sign_in_is_not_a_way_round(): void
    {
        $user = $this->signInWithPersonal(false);
        $personal = $user->personalTeam();
        $local = Team::forceCreate(['user_id' => $user->id, 'name' => 'Local only', 'personal_team' => false]);
        $mine = app(EngagementService::class)->create($personal, $user, 'Personal deal', EngagementKind::Supplier, 'Private terms', 'Quiet Partner');

        $this->get('/dashboard')->assertOk();

        foreach ([$personal, $local] as $team) {
            $this->put('/current-team', ['team_id' => $team->id])->assertRedirect();

            $this->get('/dashboard')->assertForbidden()
                ->assertSee('Dot.Engage needs a company that has switched it on')
                ->assertSee('https://infodot.co.za/platforms/engage', false)
                ->assertSee('Acme Ltd')
                ->assertDontSee('Local only')
                ->assertDontSee('Personal deal');
            $this->post('/engagements', ['title' => 'Should not exist', 'kind' => 'supplier', 'counterparty' => 'Nobody'])->assertForbidden();
        }

        // The engagement made in the personal workspace is closed to its owner too, and is still there.
        $this->get(route('engagements.show', $mine))->assertForbidden()->assertDontSee('Quiet Partner');
        $this->assertSame(1, Engagement::withoutGlobalScopes()->count());
        $this->assertTrue($mine->fresh()->isOpen());

        // InfoDot is only ever asked about the company, never about a team without an InfoDot uuid.
        Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/'.self::ACME.'/'));
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/') && ! str_contains($request->url(), self::ACME));

        // Back to the company that holds it.
        $this->put('/current-team', ['team_id' => Team::where('infodot_team_uuid', self::ACME)->value('id')])->assertRedirect();
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_personal_workspace_infodot_allowed_at_sign_in_carries_on(): void
    {
        $user = $this->signInWithPersonal(true);

        $this->put('/current-team', ['team_id' => $user->personalTeam()->id])->assertRedirect();
        $this->get('/dashboard')->assertOk();
        $this->get('/engagements')->assertOk();
    }

    public function test_a_personal_workspace_carries_on_when_the_sign_in_answer_said_nothing_about_it(): void
    {
        $user = $this->signInWithPersonal(null);

        $this->put('/current-team', ['team_id' => $user->personalTeam()->id])->assertRedirect();
        $this->get('/dashboard')->assertOk();
        $this->assertFalse(session()->has('ecosystem.personal_entitled'));
    }

    public function test_a_later_sign_in_replaces_what_was_remembered_and_the_flag_switches_it_off(): void
    {
        $user = $this->signInWithPersonal(false);
        $this->put('/current-team', ['team_id' => $user->personalTeam()->id]);
        $this->get('/dashboard')->assertForbidden();

        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->get('/dashboard')->assertOk();
        config(['ecosystem.infodot.entitlement_check' => true]);

        $this->signInWithPersonal(null);
        $this->put('/current-team', ['team_id' => $user->personalTeam()->id]);
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_guest_of_an_engagement_owned_by_a_personal_workspace_is_never_refused(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $engagement = $this->makeEngagement($owner);
        [$participant] = $this->inviteExternal($engagement);
        $participant = $this->verifyParticipant($participant);
        $this->answer([]);

        // Even a browser that remembers a refused personal workspace: without a signed-in member there is no check.
        $this->withSession([EngagementAccess::SESSION_KEY => [$engagement->id => $participant->id], 'ecosystem.personal_entitled' => false])
            ->get(route('engagements.show', $engagement))->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_livewire_update_on_a_signed_in_page_is_refused_for_a_company_without_access(): void
    {
        $user = $this->ownerOfAcme();
        $this->answer([self::url(self::ACME) => Http::sequence()
            ->push(self::body(self::ACME, true, 'active'))
            ->push(self::body(self::ACME, false, 'suspended'))]);

        $html = $this->actingAs($user)->get('/notifications')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $found), 'the page carries a Livewire component');
        $update = ['components' => [['snapshot' => html_entity_decode($found[1], ENT_QUOTES), 'updates' => (object) [], 'calls' => []]]];

        // While the company holds the platform the component answers.
        $this->actingAs($user)->postJson('/livewire/update', $update)->assertOk();

        // The company loses it (the cached answer runs out): the same update is refused and returns no component.
        $this->travel(11)->minutes();
        $this->actingAs($user)->postJson('/livewire/update', $update)
            ->assertForbidden()
            ->assertJsonMissingPath('components');
    }
}
