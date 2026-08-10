<?php

namespace App\Livewire\Contracts;

use App\Events\ContractShared;
use App\Models\Contract;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ShareModal extends Component
{
    public int $contractId;

    public bool $show = false;

    public array $selectedUsers = [];

    public function share(): void
    {
        $this->validate([
            'selectedUsers' => 'required|array|min:1',
            'selectedUsers.*' => 'integer|exists:users,id',
        ]);

        $contract = Contract::findOrFail($this->contractId);
        $this->authorize('view', $contract);

        $team = Auth::user()->currentTeam;
        $users = $team->allUsers()->whereIn('id', $this->selectedUsers);

        // NotifyContractShared owns sending ContractSharedNotification --
        // dispatching the event (rather than notifying directly) also
        // broadcasts contract.shared on the recipient's private channel.
        foreach ($users as $user) {
            ContractShared::dispatch($contract, $user);
        }

        $this->show = false;
        session()->flash('shared', 'Invitations sent successfully.');
    }

    public function render()
    {
        $contract = Contract::findOrFail($this->contractId);
        $teamMembers = Auth::user()->currentTeam
            ? Auth::user()->currentTeam->allUsers()->where('id', '!=', Auth::id())
            : collect();

        return view('livewire.contracts.share-modal', compact('teamMembers'));
    }
}
