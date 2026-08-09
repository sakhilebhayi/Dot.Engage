<?php

namespace App\Livewire\Operator;

use App\Jobs\ApplyDependencyPatchJob;
use App\Models\DependencyPatchProposal;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DependencyPatchQueue extends Component
{
    /**
     * Rejection reason text per pending proposal ID, keyed so multiple
     * pending rows never share one shared text field.
     *
     * @var array<int, string>
     */
    public array $rejectReasons = [];

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->authorizeOperator();
    }

    public function approve(int $proposalId): void
    {
        $this->authorizeOperator();

        $proposal = DependencyPatchProposal::findOrFail($proposalId);

        if ($proposal->status !== 'pending_approval') {
            $this->statusMessage = 'Only a pending proposal can be approved.';

            return;
        }

        $proposal->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        ApplyDependencyPatchJob::dispatch($proposal);

        $this->statusMessage = 'Patch approved and queued.';
    }

    public function reject(int $proposalId): void
    {
        $this->authorizeOperator();

        $proposal = DependencyPatchProposal::findOrFail($proposalId);

        if ($proposal->status !== 'pending_approval') {
            $this->statusMessage = 'Only a pending proposal can be rejected.';

            return;
        }

        $this->validate([
            "rejectReasons.{$proposalId}" => 'required|string',
        ]);

        $proposal->update([
            'status' => 'rejected',
            'rejected_reason' => $this->rejectReasons[$proposalId],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        unset($this->rejectReasons[$proposalId]);
        $this->statusMessage = 'Proposal rejected.';
    }

    /**
     * Livewire's AJAX update requests (wire:click calls) do not
     * automatically inherit the page route's "operator" middleware --
     * only the initial page load goes through it. Every action re-checks
     * this directly, and mount() covers the initial render too.
     */
    private function authorizeOperator(): void
    {
        abort_unless(Auth::user()?->is_platform_operator, 403);
    }

    public function render()
    {
        return view('livewire.operator.dependency-patch-queue', [
            'pending' => DependencyPatchProposal::where('status', 'pending_approval')->with('reviewer')->latest()->get(),
            'reviewed' => DependencyPatchProposal::whereIn('status', ['approved', 'rejected', 'applied', 'failed'])
                ->with('reviewer')->latest()->limit(20)->get(),
        ]);
    }
}
