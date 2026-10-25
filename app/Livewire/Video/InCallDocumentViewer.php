<?php

namespace App\Livewire\Video;

use App\Models\Contract;
use App\Models\VideoSession;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InCallDocumentViewer extends Component
{
    // Locked: the browser can never swap in another session or contract.
    #[Locked]
    public int $sessionId;

    #[Locked]
    public int $contractId;

    public function mount(int $sessionId, int $contractId): void
    {
        $this->sessionId = $sessionId;
        $this->contractId = $contractId;

        $session = VideoSession::findOrFail($sessionId);
        abort_unless((int) $session->contract_id === $contractId, 403);
        $this->authorize('join', $session);
        $this->authorize('view', Contract::findOrFail($contractId));
    }

    public function render()
    {
        $contract = Contract::with('signatures')->findOrFail($this->contractId);
        $this->authorize('view', $contract);

        return view('livewire.video.in-call-document-viewer', compact('contract'));
    }
}
