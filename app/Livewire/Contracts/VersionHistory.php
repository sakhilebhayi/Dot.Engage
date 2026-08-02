<?php

namespace App\Livewire\Contracts;

use App\Models\Contract;
use App\Models\ContractVersion;
use Livewire\Component;

class VersionHistory extends Component
{
    public int $contractId;

    public function mount(int $contractId): void
    {
        $this->contractId = $contractId;
        $this->authorize('view', Contract::findOrFail($contractId));
    }

    public function render()
    {
        $versions = ContractVersion::with('creator')
            ->where('contract_id', $this->contractId)
            ->orderByDesc('version_number')
            ->get();

        return view('livewire.contracts.version-history', compact('versions'));
    }
}
