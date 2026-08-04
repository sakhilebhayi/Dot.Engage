<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves HasTeamScope itself is load-bearing, independent of any Policy or
 * explicit where('team_id', ...) call: querying Contract directly as a user
 * on a different team, with no manual scoping anywhere in the path, still
 * cannot see the row. This is the property that makes the scope "defense
 * in depth" rather than decorative -- it holds even if a future controller
 * or Livewire component forgets to scope a query explicitly.
 */
class EngageTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_alone_blocks_cross_team_access_even_without_an_explicit_where(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $contract = Contract::create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'title' => 'Owner Contract',
            'status' => 'draft',
        ]);

        $this->actingAs($outsider);

        $this->assertNull(Contract::find($contract->id));
        $this->assertSame(0, Contract::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(Contract::find($contract->id));
        $this->assertSame(1, Contract::query()->count());
    }
}
