<?php

namespace Tests\Feature\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Exceptions\ClaimRequired;
use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Services\Engage\EngagementAccess;
use App\Sso\EngageProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

/** R-54 phase 2 pilot: Dot.Engage behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => EngageProvisioner::class,
            'dotsso.enabled' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        // The fake provider's redirect URI is the one this test app uses.
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create(); // two NULLs are allowed

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Remember me');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
        $this->get('/engagements')->assertRedirect('/login');
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Remember me');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/engagements')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/engagements');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_after_an_infodot_sign_out_a_real_auth_sanctum_page_ends_the_session_instead_of_a_500(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-uuid-3', 'email' => 'out@example.test'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();

        $this->travel(1)->seconds();
        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-uuid-3', 'revoked_at_ms' => (int) floor(microtime(true) * 1000), 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/dashboard')->assertRedirect('/');
        $this->assertGuest('web');
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
    }

    public function test_an_outside_party_never_meets_the_central_login(): void
    {
        $this->on();
        $engagement = $this->makeEngagement();
        [$participant, $token] = $this->inviteExternal($engagement);

        // The invitation link, the emailed-code step and the public checks answer exactly as with the flag off.
        $this->get(route('join.show', $token))->assertOk();
        $this->get('/join/short')->assertStatus(410);
        $this->get('/verify')->assertOk();
        $this->get('/how-signing-works')->assertOk();
        $this->post(route('join.code', $token))->assertSessionMissing('dotsso')->assertStatus(302);
        $this->assertNotSame(route('dotsso.login'), $this->post(route('join.code', $token))->headers->get('Location'));
    }

    public function test_a_verified_outside_party_still_reaches_the_workspace_without_an_account(): void
    {
        $this->on();
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $this->withSession([EngagementAccess::SESSION_KEY => [$engagement->id => $participant->id]]);
        $participant->forceFill(['verified_at' => now()])->save();

        $this->get(route('engagements.show', $engagement->reference))->assertOk();
        $this->assertGuest();
    }

    public function test_a_signed_out_stranger_at_a_workspace_is_refused_not_sent_to_the_central_login(): void
    {
        $this->on();
        $engagement = $this->makeEngagement();

        $response = $this->get(route('engagements.show', $engagement->reference));
        $this->assertNotSame(route('dotsso.login'), $response->headers->get('Location'));
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_the_invitation_email_offers_the_central_register_when_sso_is_on(): void
    {
        $this->on();
        // Production has local registration closed; the old email then had no way to create an account at all.
        config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);
        $owner = User::factory()->withPersonalTeam()->create();
        $invitation = $owner->ownedTeams()->first()->teamInvitations()->create(['email' => 'new@example.test', 'role' => 'editor']);

        $html = (new TeamInvitation($invitation))->render();

        $this->assertStringContainsString('Create Account', $html);
        $this->assertStringContainsString('/sso/login?screen=register', html_entity_decode($html));
    }

    public function test_the_receiver_no_longer_replaces_an_unverified_accounts_password(): void
    {
        $user = User::factory()->create(['email' => 'amara@example.test', 'email_verified_at' => null, 'password' => Hash::make('keep-me-1')]);
        $hash = $user->password;
        $provision = app(ProvisionUserFromInfoDot::class);

        $this->expectException(ClaimRequired::class);
        try {
            $provision(['user' => ['uuid' => 'u-1', 'email' => 'amara@example.test', 'email_verified' => true, 'name' => 'Amara']]);
        } finally {
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertNull($user->fresh()->email_verified_at);
        }
    }

    public function test_a_real_auth_sanctum_route_keeps_the_session_guard_default(): void
    {
        $this->assertSame('web', config('dotsso.guard'));
    }
}
