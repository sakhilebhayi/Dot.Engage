<?php

namespace Tests\Unit;

use App\Jobs\ApplyDependencyPatchJob;
use App\Models\DependencyPatchProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyDependencyPatchJobTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(string $command): DependencyPatchProposal
    {
        return DependencyPatchProposal::create([
            'manager' => 'composer',
            'advisories' => [['package' => 'league/commonmark', 'severity' => 'moderate', 'title' => 'x', 'identifier' => 'y']],
            'risk_summary' => '1 advisory: 1 moderate',
            'proposed_command' => $command,
            'status' => 'approved',
        ]);
    }

    public function test_a_successful_command_marks_the_proposal_applied(): void
    {
        $proposal = $this->proposal('echo patch-output-ok');

        (new ApplyDependencyPatchJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertSame('applied', $fresh->status);
        $this->assertStringContainsString('patch-output-ok', $fresh->applied_log);
        $this->assertNotNull($fresh->applied_at);
    }

    public function test_a_failing_command_marks_the_proposal_failed(): void
    {
        // "false" is a real shell command that always exits 1 -- never a
        // real package-manager mutation.
        $proposal = $this->proposal('false');

        (new ApplyDependencyPatchJob($proposal))->handle();

        $fresh = $proposal->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->applied_at);
    }
}
