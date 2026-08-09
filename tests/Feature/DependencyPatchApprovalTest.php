<?php

namespace Tests\Feature;

use App\Jobs\ApplyDependencyPatchJob;
use App\Livewire\Operator\DependencyPatchQueue;
use App\Models\DependencyPatchProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DependencyPatchApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    private function pendingProposal(): DependencyPatchProposal
    {
        return DependencyPatchProposal::create([
            'manager' => 'composer',
            'advisories' => [['package' => 'league/commonmark', 'severity' => 'moderate', 'title' => 'x', 'identifier' => 'y']],
            'risk_summary' => '1 advisory: 1 moderate',
            'proposed_command' => 'composer update --with-dependencies',
        ]);
    }

    public function test_non_operator_is_refused_on_the_review_route(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('operator.dependency-patches.index'))
            ->assertForbidden();
    }

    public function test_operator_can_view_the_review_route(): void
    {
        $this->pendingProposal();

        $this->actingAs($this->operator())
            ->get(route('operator.dependency-patches.index'))
            ->assertOk();
    }

    public function test_non_operator_is_refused_by_the_component_itself(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(DependencyPatchQueue::class)->assertForbidden();
    }

    public function test_approving_dispatches_the_apply_job(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $this->actingAs($operator);

        Livewire::test(DependencyPatchQueue::class)
            ->call('approve', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        Queue::assertPushed(ApplyDependencyPatchJob::class, fn ($job) => $job->proposal->is($fresh));
    }

    public function test_rejecting_without_a_reason_is_blocked(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $this->actingAs($this->operator());

        Livewire::test(DependencyPatchQueue::class)
            ->call('reject', $proposal->id)
            ->assertHasErrors(["rejectReasons.{$proposal->id}" => 'required']);

        $this->assertSame('pending_approval', $proposal->fresh()->status);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }

    public function test_rejecting_with_a_reason_marks_it_rejected(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $this->actingAs($operator);

        Livewire::test(DependencyPatchQueue::class)
            ->set("rejectReasons.{$proposal->id}", 'Will patch manually next release.')
            ->call('reject', $proposal->id);

        $fresh = $proposal->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Will patch manually next release.', $fresh->rejected_reason);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }

    public function test_approving_an_already_decided_proposal_is_refused(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $proposal->update(['status' => 'rejected', 'rejected_reason' => 'Already handled.']);
        $this->actingAs($this->operator());

        Livewire::test(DependencyPatchQueue::class)
            ->call('approve', $proposal->id);

        $this->assertSame('rejected', $proposal->fresh()->status);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }

    public function test_rejecting_an_already_decided_proposal_is_refused(): void
    {
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $proposal->update(['status' => 'approved', 'reviewed_by' => $operator->id, 'reviewed_at' => now()]);
        $this->actingAs($operator);

        Livewire::test(DependencyPatchQueue::class)
            ->set("rejectReasons.{$proposal->id}", 'Too late.')
            ->call('reject', $proposal->id);

        $this->assertSame('approved', $proposal->fresh()->status);
    }

    public function test_action_level_guard_blocks_a_revoked_operator_mid_session(): void
    {
        Queue::fake();
        $proposal = $this->pendingProposal();
        $operator = $this->operator();
        $this->actingAs($operator);

        $component = Livewire::test(DependencyPatchQueue::class);

        // Simulate the operator flag being revoked after the component
        // already mounted -- mount() only runs once, so only the
        // action-level guard inside approve() itself can catch this.
        $operator->forceFill(['is_platform_operator' => false])->save();

        $component->call('approve', $proposal->id)->assertForbidden();

        $this->assertSame('pending_approval', $proposal->fresh()->status);
        Queue::assertNotPushed(ApplyDependencyPatchJob::class);
    }
}
