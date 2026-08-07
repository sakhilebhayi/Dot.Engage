<?php

namespace App\Livewire\Contracts;

use App\Events\ContractSigned;
use App\Jobs\GenerateSignedContractPdf;
use App\Models\Contract;
use App\Models\ContractSignature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class SignaturePad extends Component
{
    public int $contractId;

    /**
     * Receives the base64 PNG from the Alpine canvas and persists it.
     */
    public function saveSignature(int $contractId, string $signatureData): void
    {
        $contract = Contract::findOrFail($contractId);
        $this->authorize('sign', $contract);

        // Decode and store the image on the private signatures disk.
        $data = preg_replace('/^data:image\/\w+;base64,/', '', $signatureData);
        $decoded = base64_decode($data);
        $filename = 'sig_'.Auth::id().'_'.Str::uuid().'.png';
        Storage::disk('signatures')->put($filename, $decoded);

        $signature = ContractSignature::create([
            'contract_id' => $contractId,
            'user_id' => Auth::id(),
            'signature_image_path' => $filename,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'signed_at' => now(),
        ]);

        // Mark as signed if all team members have signed.
        $teamMemberCount = $contract->team->allUsers()->count();
        $signatureCount = $contract->signatures()->count();

        if ($signatureCount >= $teamMemberCount) {
            $contract->update(['status' => 'signed']);
            ContractSigned::dispatch($contract, $signature);
            GenerateSignedContractPdf::dispatch($contract);
        }

        $this->dispatch('contract-signed', contractId: $contractId);
    }

    public function render()
    {
        return view('livewire.contracts.signature-pad', [
            'contractId' => $this->contractId,
        ]);
    }
}
