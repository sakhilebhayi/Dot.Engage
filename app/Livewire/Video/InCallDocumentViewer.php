<?php

namespace App\Livewire\Video;

use App\Models\Contract;
use Livewire\Component;

class InCallDocumentViewer extends Component
{
    public int $sessionId;

    public int $contractId;

    public function mount(int $sessionId, int $contractId): void
    {
        $this->sessionId = $sessionId;
        $this->contractId = $contractId;
        $this->authorize('view', Contract::findOrFail($contractId));
    }

    public function render()
    {
        $contract = Contract::with('signatures')->findOrFail($this->contractId);

        return view('livewire.video.in-call-document-viewer', compact('contract'));
    }
}
