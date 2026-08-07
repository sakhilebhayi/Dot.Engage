<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;
use App\Policies\ContractPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ContractPolicy;
    }

    // -----------------------------------------------------------------------
    // ContractPolicy::sign
    // -----------------------------------------------------------------------

    public function test_sign_policy_allows_unsigned_team_member(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue($this->policy->sign($owner, $contract));
    }

    public function test_sign_policy_denies_non_team_member(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertFalse($this->policy->sign($outsider, $contract));
    }

    public function test_sign_policy_denies_already_signed_user(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        // Create an existing signature record for the owner.
        ContractSignature::create([
            'contract_id' => $contract->id,
            'user_id' => $owner->id,
            'signature_image_path' => 'sig_test.png',
            'signed_at' => now(),
        ]);

        $this->assertFalse($this->policy->sign($owner, $contract));
    }

    // -----------------------------------------------------------------------
    // ContractPolicy::update — status guard
    // -----------------------------------------------------------------------

    public function test_update_policy_denies_any_user_on_signed_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->signed()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertFalse($this->policy->update($owner, $contract));
    }

    public function test_update_policy_allows_creator_on_draft_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->draft()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue($this->policy->update($owner, $contract));
    }

    // -----------------------------------------------------------------------
    // ContractPolicy::restore / forceDelete — admin only
    // -----------------------------------------------------------------------

    public function test_restore_is_restricted_to_team_admin(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $admin = User::factory()->create();
        $editor = User::factory()->create();
        $owner->currentTeam->users()->attach($admin, ['role' => 'admin']);
        $owner->currentTeam->users()->attach($editor, ['role' => 'editor']);

        $contract = Contract::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue($this->policy->restore($admin, $contract));
        $this->assertFalse($this->policy->restore($editor, $contract));
    }
}
