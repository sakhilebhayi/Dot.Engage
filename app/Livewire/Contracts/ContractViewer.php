<?php

namespace App\Livewire\Contracts;

use App\Models\Contract;
use Livewire\Component;

class ContractViewer extends Component
{
    public int $contractId;

    public function mount(int $contractId): void
    {
        $this->authorize('view', Contract::findOrFail($contractId));
    }

    public function render()
    {
        $contract = Contract::with(['signatures.user', 'signatures.externalSigner', 'versions.creator', 'externalSigners'])
            ->findOrFail($this->contractId);

        return view('livewire.contracts.contract-viewer', compact('contract'));
    }
}
