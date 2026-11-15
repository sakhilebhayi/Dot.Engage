<?php

namespace Tests\Feature;

use App\Enums\EngagementKind;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\EngagementApproval;
use App\Models\Team;
use App\Models\User;
use App\Services\Engage\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The desk (R-51): state headline, waiting list, ledger, the one selected sheet, and the ?e= selection rule. */
class DashboardDeskTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $name = 'Naledi Dlamini', string $business = 'Dlamini Logistics'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $team = $user->ownedTeams()->create(['name' => $business, 'personal_team' => false]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function approvalFor(User $user, $engagement): void
    {
        $contract = Contract::factory()->create(['team_id' => $engagement->team_id, 'engagement_id' => $engagement->id, 'created_by' => $user->id, 'file_path' => null, 'status' => 'pending']);
        $version = ContractVersion::create(['contract_id' => $contract->id, 'created_by' => $user->id, 'version_number' => 1, 'kind' => 'original', 'file_path' => 'x.pdf', 'change_notes' => 'x']);
        $participant = $engagement->participants()->where('user_id', $user->id)->first();
        $participant->update(['capabilities' => ['approve']]);
        EngagementApproval::create(['engagement_id' => $engagement->id, 'contract_version_id' => $version->id, 'participant_id' => $participant->id, 'status' => 'pending', 'requested_at' => now()]);
    }

    private function sheetReference(string $html): ?string
    {
        preg_match('/id="selected".*?<span class="fig">(ENG-\d{4}-\d{5})<\/span>/s', $html, $m);

        return $m[1] ?? null;
    }

    public function test_the_headline_states_the_state_of_the_desk_from_the_count_waiting(): void
    {
        $owner = $this->owner();
        $service = app(EngagementService::class);
        $a = $service->create($owner->currentTeam, $owner, 'Supply terms', EngagementKind::Supplier, null, 'Acme');

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('<h1 id="h-state">You are clear.</h1>', false);

        $this->approvalFor($owner, $a);
        $this->actingAs($owner)->get('/dashboard')->assertSee('<h1 id="h-state">One thing waits for you.</h1>', false)
            ->assertSee('Approve version 1')
            ->assertSee('<span class="n">1</span>', false);
    }

    public function test_the_sheet_defaults_to_the_engagement_that_is_waiting_not_just_the_latest(): void
    {
        $owner = $this->owner();
        $service = app(EngagementService::class);
        $waiting = $service->create($owner->currentTeam, $owner, 'Waiting one', EngagementKind::Supplier, null, 'Acme');
        $this->travel(5)->minutes();
        $latest = $service->create($owner->currentTeam, $owner, 'Newest one', EngagementKind::Commercial, null, 'Beta');
        $this->approvalFor($owner, $waiting);

        $html = $this->actingAs($owner)->get('/dashboard')->getContent();

        $this->assertSame($waiting->reference, $this->sheetReference($html));
        $this->assertNotSame($latest->reference, $this->sheetReference($html));
    }

    public function test_a_valid_e_parameter_picks_a_row_already_in_the_ledger(): void
    {
        $owner = $this->owner();
        $service = app(EngagementService::class);
        $first = $service->create($owner->currentTeam, $owner, 'First supply terms', EngagementKind::Supplier, null, 'Acme');
        $this->travel(5)->minutes();
        $service->create($owner->currentTeam, $owner, 'Second retainer letter', EngagementKind::Commercial, null, 'Beta');

        $html = $this->actingAs($owner)->get(route('dashboard', ['e' => $first->reference]))->assertOk()->getContent();

        $this->assertSame($first->reference, $this->sheetReference($html));
        $this->assertStringContainsString('class="desk is-reselect"', $html);
        $this->assertMatchesRegularExpression('/<tr\s+aria-current="true"\s*>\s*<td class="eng"><a href="[^"]*e='.preg_quote($first->reference, '/').'/', $html);
    }

    public function test_an_e_parameter_from_another_business_or_made_up_falls_back_and_leaks_nothing(): void
    {
        $owner = $this->owner();
        $other = $this->owner('Someone Else', 'Another Business');
        $service = app(EngagementService::class);
        $mine = $service->create($owner->currentTeam, $owner, 'My own terms', EngagementKind::Supplier, null, 'Acme');
        $foreign = $service->create($other->currentTeam, $other, 'Foreign secret deal', EngagementKind::Commercial, null, 'Them');

        foreach ([$foreign->reference, 'ENG-1999-00000', '<script>x</script>'] as $e) {
            $response = $this->actingAs($owner)->get(route('dashboard', ['e' => $e]))->assertOk();
            $this->assertSame($mine->reference, $this->sheetReference($response->getContent()), 'falls back to the default rule');
            $response->assertDontSee('Foreign secret deal')->assertDontSee($foreign->reference);
        }

        $this->actingAs($owner)->get('/dashboard?e[]=x')->assertOk();
    }

    public function test_the_sheet_shows_a_fact_per_stage_and_the_next_action_for_the_viewer(): void
    {
        $owner = $this->owner();
        $e = app(EngagementService::class)->create($owner->currentTeam, $owner, 'Supply terms', EngagementKind::Supplier, null, 'Acme');
        $viewer = $this->member($owner->currentTeam, 'viewer');

        $html = $this->actingAs($owner)->get('/dashboard')->getContent();
        $this->assertSame(6, preg_match_all('/<span class="nm">(Engage|Discuss|Negotiate|Approve|Sign|Complete)<\/span><span class="fact">[^<]+<\/span>/', $html));
        $this->assertStringContainsString('Invite the other party', $html);

        $viewerHtml = $this->actingAs($viewer)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('Invite the other party', $viewerHtml);
        $this->assertStringContainsString('Waiting for the owner to invite the other party', $viewerHtml);
        $this->assertSame(1, substr_count($viewerHtml, 'data-primary-action'));
        $this->assertStringContainsString('See the engagements', $viewerHtml);
        $this->assertStringNotContainsString('href="'.route('engagements.create').'"', $viewerHtml);
        $this->assertSame($e->reference, $this->sheetReference($viewerHtml));
    }

    public function test_the_foot_counts_are_counted_from_this_business_records_only(): void
    {
        $owner = $this->owner();
        $other = $this->owner('Someone Else', 'Another Business');
        $service = app(EngagementService::class);
        $service->create($owner->currentTeam, $owner, 'One', EngagementKind::Supplier, null, 'Acme');
        $service->create($owner->currentTeam, $owner, 'Two', EngagementKind::Supplier, null, 'Beta');
        $service->create($other->currentTeam, $other, 'Theirs', EngagementKind::Supplier, null, 'Gamma');

        $this->actingAs($owner)->get('/dashboard')->assertSee('2 active engagements, 0 waiting for you, 0 meetings, 0 proposed changes. Counted from the records of Dlamini Logistics.');
    }

    public function test_the_empty_member_sees_the_empty_variant_with_one_primary_action_and_no_ledger(): void
    {
        $member = $this->owner('Thabo Mokoena', 'Mokoena Attorneys');

        $html = $this->actingAs($member)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 id="h-state">No engagements yet.</h1>', $html);
        $this->assertStringNotContainsString('desk-ledger', $html);
        $this->assertSame(1, substr_count($html, 'data-primary-action'));
        $this->assertSame(6, preg_match_all('/<li>(Engage|Discuss|Negotiate|Approve|Sign|Complete)<\/li>/', $html));
    }

    public function test_motion_is_reduced_motion_safe_and_no_inline_script_was_added(): void
    {
        $css = file_get_contents(resource_path('css/engage/landing.css'));
        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $css);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertDoesNotMatchRegularExpression('/\b(width|height|top|left)\s*:[^;]*;[^}]*animation/', $css);

        $html = $this->actingAs($this->owner())->get('/dashboard')->getContent();
        $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', $html), 'the desk adds no inline script');
    }
}
