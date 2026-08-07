<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Policies\ContractPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Policy: view
    // -----------------------------------------------------------------------

    public function test_team_member_can_view_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $policy = new ContractPolicy;

        $this->assertTrue($policy->view($owner, $contract));
    }

    public function test_outsider_cannot_view_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        $policy = new ContractPolicy;

        $this->assertFalse($policy->view($outsider, $contract));
    }

    // -----------------------------------------------------------------------
    // Policy: update
    // -----------------------------------------------------------------------

    public function test_creator_can_update_draft_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->draft()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $policy = new ContractPolicy;

        $this->assertTrue($policy->update($owner, $contract));
    }

    public function test_signed_contract_cannot_be_updated_by_anyone(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->signed()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $policy = new ContractPolicy;

        $this->assertFalse($policy->update($owner, $contract));
    }

    public function test_non_creator_member_cannot_update_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $policy = new ContractPolicy;

        $this->assertFalse($policy->update($member, $contract));
    }

    public function test_admin_can_update_pending_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $admin = User::factory()->create();
        $owner->currentTeam->users()->attach($admin, ['role' => 'admin']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $policy = new ContractPolicy;

        $this->assertTrue($policy->update($admin, $contract));
    }

    // -----------------------------------------------------------------------
    // Policy: delete
    // -----------------------------------------------------------------------

    public function test_creator_can_delete_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->draft()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $policy = new ContractPolicy;

        $this->assertTrue($policy->delete($owner, $contract));
    }

    public function test_outsider_cannot_delete_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->draft()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        $policy = new ContractPolicy;

        $this->assertFalse($policy->delete($outsider, $contract));
    }

    // -----------------------------------------------------------------------
    // Model relationships
    // -----------------------------------------------------------------------

    public function test_contract_belongs_to_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue($contract->team->is($owner->currentTeam));
    }

    public function test_contract_has_creator(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue($contract->creator->is($owner));
    }
}
