<?php

namespace Tests\Feature;

use App\Enums\EngagementKind;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\EngagementApproval;
use App\Models\User;
use App\Services\Engage\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_for_authenticated_user_with_a_current_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_shows_this_teams_engagements_and_not_another_teams(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $service = app(EngagementService::class);

        $service->create($user->currentTeam, $user, 'Acme retainer agreement', EngagementKind::Commercial, null, 'Acme');
        $service->create($other->currentTeam, $other, 'Somebody elses secret deal', EngagementKind::Commercial, null, 'Them');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Acme retainer agreement');
        $response->assertDontSee('Somebody elses secret deal');
        $response->assertSee('Team chat');
    }

    public function test_the_desk_says_what_is_waiting_for_the_person_from_real_rows(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $service = app(EngagementService::class);
        $engagement = $service->create($owner->currentTeam, $owner, 'Supply terms', EngagementKind::Supplier, null, 'Acme');

        $this->actingAs($owner)->get('/dashboard')->assertSee('You are clear');

        $contract = Contract::factory()->create(['team_id' => $owner->currentTeam->id, 'engagement_id' => $engagement->id, 'created_by' => $owner->id, 'file_path' => null, 'status' => 'pending']);
        $version = ContractVersion::create(['contract_id' => $contract->id, 'created_by' => $owner->id, 'version_number' => 1, 'kind' => 'original', 'file_path' => 'x.pdf', 'change_notes' => 'x']);
        $participant = $engagement->participants()->where('user_id', $owner->id)->first();
        $participant->update(['capabilities' => ['approve']]);
        EngagementApproval::create(['engagement_id' => $engagement->id, 'contract_version_id' => $version->id, 'participant_id' => $participant->id, 'status' => 'pending', 'requested_at' => now()]);

        $this->actingAs($owner)->get('/dashboard')->assertSee('Approve version 1')->assertSee('Supply terms');
    }
}
