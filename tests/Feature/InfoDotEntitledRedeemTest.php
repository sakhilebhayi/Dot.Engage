<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R-50 at sign-in: InfoDot's redeem answer marks each company with `entitled` and `access`. Only a company marked
 * exactly true is created, updated or made current here; an answer without the key (an InfoDot that does not send it
 * yet) behaves as it always did; when nothing is entitled the person sees a plain page and nobody is signed in.
 */
class InfoDotEntitledRedeemTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL, 'platform' => 'engage', 'secret' => Str::random(40), 'timeout' => 2, 'provision' => true, 'entitlement_check' => true,
        ]]);
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([self::URL => Http::response([
            'platform' => 'engage',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test']);
    }

    /** One company as the redeem answer lists it; $entitled null leaves the keys out (an older InfoDot). */
    private function company(string $uuid, string $name, string $role, ?bool $entitled = true, ?string $access = null, bool $personal = false): array
    {
        return ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role, 'is_owner' => $role === 'owner']
            + ($entitled === null ? [] : ['entitled' => $entitled, 'access' => $entitled ? null : ($access ?? 'not_activated')]);
    }

    public function test_an_entitled_company_is_admitted_and_becomes_current(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_that_is_not_entitled_is_not_created_and_cannot_become_current(): void
    {
        $this->signIn('ann@acme.test', [
            $this->company(self::ACME, 'Acme Ltd', 'owner'),
            $this->company(self::GLOBEX, 'Globex Ltd', 'owner', false, 'suspended'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first(), 'the company without the platform is not created');
        $this->assertNotNull($acme = Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertNotSame(null, $user->current_team_id);
        $this->assertNotSame('Globex Ltd', $user->currentTeam->name);
        $this->assertTrue($user->belongsToTeam($acme));
    }

    public function test_a_company_that_lost_the_platform_is_left_as_it_is_and_the_person_is_moved_off_it(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner'), $this->company(self::GLOBEX, 'Globex Ltd', 'owner')], self::GLOBEX);
        $this->signIn('bob@acme.test', [$this->company(self::GLOBEX, 'Globex Ltd', 'editor')], self::GLOBEX);

        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $bob = User::where('email', 'bob@acme.test')->firstOrFail();
        $this->assertSame($globex->id, $ann->current_team_id);
        $this->assertSame($globex->id, $bob->current_team_id);

        // Globex stops holding the platform; InfoDot also reports a new name and a lower role for Bob.
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner'), $this->company(self::GLOBEX, 'Globex Renamed', 'owner', false, 'payment_required')], self::GLOBEX)
            ->assertRedirect(route('dashboard'));

        $this->assertSame('Globex Ltd', $globex->fresh()->name, 'not updated');
        $this->assertSame(Team::where('infodot_team_uuid', self::ACME)->value('id'), $ann->fresh()->current_team_id, 'moved to the company that holds it');
        $this->assertTrue($ann->fresh()->belongsToTeam($globex), 'ownership untouched');

        // Bob has only Globex: InfoDot itself refuses such a sign-in, but if the answer arrives he sees the page.
        $this->signIn('bob@acme.test', [$this->company(self::GLOBEX, 'Globex Ltd', 'viewer', false, 'payment_required')], self::GLOBEX)->assertForbidden();
        $this->assertTrue($bob->fresh()->belongsToTeam($globex), 'membership untouched');
        $this->assertSame('editor', $globex->users()->whereKey($bob->id)->first()->membership->role, 'role untouched');
    }

    public function test_an_answer_without_the_key_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [$this->company(self::ACME, 'Acme Ltd', 'owner', null)], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Team::where('infodot_team_uuid', self::ACME)->value('id'), $user->current_team_id);
    }

    public function test_only_exactly_true_admits(): void
    {
        foreach ([1, 'true', 'yes', null, 0] as $i => $value) {
            $uuid = (string) Str::uuid();
            $team = ['id' => 5, 'uuid' => $uuid, 'name' => 'Loose '.$i, 'personal' => false, 'role' => 'owner', 'entitled' => $value, 'access' => null];

            $this->signIn('loose'.$i.'@acme.test', [$team], $uuid)->assertForbidden();
            $this->assertNull(Team::where('infodot_team_uuid', $uuid)->first());
        }

        $this->assertGuest();
    }

    public function test_nothing_entitled_shows_the_plain_page_and_signs_nobody_in(): void
    {
        $response = $this->signIn('new@globex.test', [
            $this->company((string) Str::uuid(), "New's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->company(self::GLOBEX, 'Globex Ltd', 'owner', false, 'suspended'),
        ], self::GLOBEX);

        $response->assertForbidden()
            ->assertSee('Dot.Engage is suspended for Globex Ltd')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('https://infodot.co.za/platforms/engage', false)
            ->assertDontSee('one-time')
            ->assertDontSee('Whoops');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'new@globex.test')->first(), 'no account is created');
        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
        $this->assertSame(1, substr_count($response->getContent(), 'class="btn"'));
    }

    public function test_a_personal_workspace_follows_what_infodot_said(): void
    {
        $personal = fn (?bool $entitled) => [$this->company((string) Str::uuid(), "Sam's Team", 'owner', $entitled, 'no_personal_access', personal: true)];

        $this->signIn('sam@solo.test', $personal(false))->assertForbidden()->assertSee('Dot.Engage needs a company');
        $this->assertGuest();

        $this->signIn('sam@solo.test', $personal(true))->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->signIn('sal@solo.test', $personal(null))->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_the_flag_switched_off_admits_every_listed_company_as_before(): void
    {
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->signIn('ann@acme.test', [$this->company(self::GLOBEX, 'Globex Ltd', 'owner', false, 'suspended')], self::GLOBEX)->assertRedirect(route('dashboard'));

        $this->assertNotNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
    }
}
