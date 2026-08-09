<?php

namespace Tests\Unit;

use App\Models\DependencyPatchProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyPatchProposalModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_advisories_cast_to_array_and_status_defaults_to_pending(): void
    {
        $reviewer = User::factory()->create();

        $proposal = DependencyPatchProposal::create([
            'manager' => 'composer',
            'advisories' => [
                ['package' => 'league/commonmark', 'severity' => 'moderate', 'title' => 'DoS via crafted input', 'identifier' => 'GHSA-abcd'],
            ],
            'risk_summary' => '1 advisory: 1 moderate',
            'proposed_command' => 'composer update --with-dependencies',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $proposal->fresh();
        $this->assertSame('pending_approval', $fresh->status);
        $this->assertIsArray($fresh->advisories);
        $this->assertSame('league/commonmark', $fresh->advisories[0]['package']);
        $this->assertTrue($fresh->reviewer->is($reviewer));
    }
}
