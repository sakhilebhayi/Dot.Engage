<?php

namespace App\Livewire\Contracts;

use App\Models\Contract;
use App\Models\ContractExternalSigner;
use App\Notifications\ExternalSignatureRequestNotification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\On;
use Livewire\Component;

class InviteExternalSigner extends Component
{
    use AuthorizesRequests;

    public int $contractId;

    public bool $show = false;

    public string $name = '';

    public string $email = '';

    public int $expiresInDays = 14;

    /**
     * When checked, this signer is appended to the end of the contract's
     * ordered signing queue and must wait for every previously-invited
     * ordered signer to sign first. Unchecked (the default) means "may
     * sign any time," matching the pre-existing behavior.
     */
    public bool $enforceOrder = false;

    #[On('open-invite-external-signer')]
    public function open(int $contractId): void
    {
        $this->contractId = $contractId;
        $this->show = true;
    }

    public function invite(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'expiresInDays' => 'required|integer|min:1|max:90',
        ]);

        $contract = Contract::findOrFail($this->contractId);

        // Sharing outside the organization is a bigger action than sharing
        // internally (ShareModal only requires 'view') -- restrict it to
        // whoever is already allowed to manage the contract itself.
        $this->authorize('update', $contract);

        $expiresAt = now()->addDays($this->expiresInDays);

        // Appending to the existing ordered queue for this contract, if
        // ordering was requested -- the new invite signs last.
        $signOrder = null;
        if ($this->enforceOrder) {
            $signOrder = 1 + (int) ContractExternalSigner::where('contract_id', $contract->id)
                ->whereNotNull('sign_order')
                ->max('sign_order');
        }

        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => Auth::id(),
            'name' => $this->name,
            'email' => $this->email,
            'status' => 'pending',
            'sign_order' => $signOrder,
            'expires_at' => $expiresAt,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'external.contracts.show',
            $expiresAt,
            ['signer' => $signer->id],
        );

        Notification::route('mail', $this->email)
            ->notify(new ExternalSignatureRequestNotification($contract, $signer, $signedUrl));

        $this->reset(['name', 'email', 'enforceOrder']);
        $this->expiresInDays = 14;
        $this->show = false;
        session()->flash('external-invited', 'Signing invitation sent to '.$signer->email.'.');
    }

    public function render()
    {
        $externalSigners = ContractExternalSigner::where('contract_id', $this->contractId)
            ->latest()
            ->get();

        return view('livewire.contracts.invite-external-signer', compact('externalSigners'));
    }
}
