<?php

namespace Tests\Feature;

use App\Enums\EngagementKind;
use App\Models\Team;
use App\Models\User;
use App\Services\Engage\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The page a person lands on after signing in: the real logo, a welcome from real data, one primary action that leads to
 * a page that works for that person, the most recent engagement as it stands, and an honest empty state.
 */
class LandingExperienceTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO_SHA256 = '3ac4e027b986075780b928e6930c1961230bebff8ad19e6ad5b8589793ac0c12';

    private const LOGO_LIGHT_SHA256 = '17ceb5e346b20b54bd294c57baf5c29a7da70c28a7403b6ed3c1bfbe1b5ec290';

    private function owner(string $name = 'Naledi Dlamini', string $business = 'Dlamini Logistics'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $team = $user->ownedTeams()->create(['name' => $business, 'personal_team' => false]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function member(Team $team, string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** @return list<string> the href of every element that carries the primary-action marker */
    private function primaryActions(TestResponse $response): array
    {
        preg_match_all('/<a\b[^>]*\bdata-primary-action\b[^>]*>/', (string) $response->getContent(), $tags);

        $this->assertSame(substr_count((string) $response->getContent(), 'data-primary-action'), count($tags[0]), 'The marker sits on something other than a link.');

        return array_map(function (string $tag) {
            preg_match('/href="([^"]*)"/', $tag, $m);

            return html_entity_decode($m[1] ?? '');
        }, $tags[0]);
    }

    /** @return list<string> every count the page prints beside a section heading */
    private function printedCounts(TestResponse $response): array
    {
        preg_match_all('/<span class="n">([^<]*)<\/span>/', (string) $response->getContent(), $m);

        return $m[1];
    }

    public function test_an_owner_is_welcomed_by_name_and_business_and_gets_one_primary_action_that_works(): void
    {
        $owner = $this->owner();
        app(EngagementService::class)->create($owner->currentTeam, $owner, 'Cold-chain transport agreement', EngagementKind::Supplier, null, 'Karoo Fresh Produce');

        $response = $this->actingAs($owner)->get('/dashboard')->assertOk();

        $response->assertSee('Welcome to Dot.Engage, <b>Naledi</b>. You are at the desk of <b>Dlamini Logistics</b>.', false);
        $this->assertSame([route('engagements.create')], $this->primaryActions($response));
        $this->actingAs($owner)->get(route('engagements.create'))->assertOk()->assertSee('New engagement');
    }

    public function test_an_editor_gets_the_same_primary_action_and_it_works_for_them(): void
    {
        $owner = $this->owner();
        $editor = $this->member($owner->currentTeam, 'editor', 'Sipho Nkosi');

        $response = $this->actingAs($editor)->get('/dashboard')->assertOk()->assertSee('Sipho');

        $this->assertSame([route('engagements.create')], $this->primaryActions($response));
        $this->actingAs($editor)->get(route('engagements.create'))->assertOk();
    }

    public function test_a_viewer_who_may_not_start_an_engagement_is_sent_somewhere_that_works(): void
    {
        $owner = $this->owner();
        $viewer = $this->member($owner->currentTeam, 'viewer', 'Lerato Khumalo');

        $this->actingAs($viewer)->get(route('engagements.create'))->assertForbidden();

        $response = $this->actingAs($viewer)->get('/dashboard')->assertOk()->assertSee('Lerato');

        $this->assertSame([route('engagements.index')], $this->primaryActions($response));
        $response->assertDontSee('href="'.route('engagements.create').'"', false);
        $this->actingAs($viewer)->get(route('engagements.index'))->assertOk();
    }

    public function test_the_landing_shows_the_original_logo_files(): void
    {
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', public_path('images/logo.png')));
        $this->assertSame(self::LOGO_LIGHT_SHA256, hash_file('sha256', public_path('images/logo-light.png')));

        $response = $this->actingAs($this->owner())->get('/dashboard')->assertOk();

        $response->assertSee('src="'.asset('images/logo.png').'" alt="Dot.Engage"', false);
        $response->assertSee('src="'.asset('images/logo-light.png').'" alt="Dot.Engage"', false);
    }

    public function test_the_way_back_to_infodot_comes_from_the_ecosystem_config(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get('/dashboard')
            ->assertSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.co.za/platforms">Platforms</a>', false);

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test/']);

        $this->actingAs($owner)->get('/dashboard')
            ->assertSee('<a href="https://infodot.test/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.test/platforms">Platforms</a>', false);
    }

    public function test_a_new_member_with_nothing_sees_a_plain_empty_state_and_no_numbers(): void
    {
        $member = $this->owner('Thabo Mokoena', 'Mokoena Attorneys');

        $response = $this->actingAs($member)->get('/dashboard')->assertOk();

        $response->assertSee('No engagements yet.')
            ->assertSee('Nothing is waiting on you and no meeting is booked.')
            ->assertSee('Thabo')
            ->assertSee('Mokoena Attorneys')
            ->assertDontSee('Waiting for you')
            ->assertDontSee('Recently completed');
        $this->assertSame([], $this->printedCounts($response));
        $this->assertDoesNotMatchRegularExpression('/ENG-\d{4}-\d{5}/', (string) $response->getContent());
        $this->assertSame([route('engagements.create')], $this->primaryActions($response));
    }

    public function test_every_count_and_the_sheet_come_from_this_business_own_records(): void
    {
        $owner = $this->owner();
        $other = $this->owner('Someone Else', 'Another Business');
        $service = app(EngagementService::class);

        $service->create($owner->currentTeam, $owner, 'First supply terms', EngagementKind::Supplier, null, 'Acme');
        $this->travel(1)->minutes();
        $latest = $service->create($owner->currentTeam, $owner, 'Second retainer letter', EngagementKind::Commercial, null, 'Karoo Fresh Produce');
        $this->travel(1)->minutes();
        $service->create($other->currentTeam, $other, 'Not this business', EngagementKind::Commercial, null, 'Them');

        $response = $this->actingAs($owner)->get('/dashboard')->assertOk();

        // Waiting for you, meetings, active engagements, out for signature, changes to review: in page order.
        $this->assertSame(['0', '0', '2', '0', '0'], $this->printedCounts($response));
        $response->assertSeeInOrder(['id="h-latest"', $latest->reference, 'Second retainer letter', 'Karoo Fresh Produce', $latest->state->nextStep()], false);
        $this->assertMatchesRegularExpression('/<li class="is-now"\s+aria-current="step"\s*>Engage<\/li>/', (string) $response->getContent());
        $response->assertDontSee('Not this business');
        $response->assertDontSee('No engagements yet.');
    }
}
