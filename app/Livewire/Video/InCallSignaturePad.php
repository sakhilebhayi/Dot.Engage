<?php

namespace App\Livewire\Video;

use App\Events\SignatureRequestedDuringCall;
use App\Models\Contract;
use App\Models\VideoSession;
use App\Models\VideoSessionSignature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class InCallSignaturePad extends Component
{
    public int $sessionId;

    public int $contractId;

    public function sign(string $signatureData): void
    {
        $contract = Contract::findOrFail($this->contractId);
        $this->authorize('sign', $contract);

        $data = preg_replace('/^data:image\/\w+;base64,/', '', $signatureData);
        $decoded = base64_decode($data);
        $filename = 'vsig_'.Auth::id().'_'.Str::uuid().'.png';
        Storage::disk('signatures')->put($filename, $decoded);

        VideoSessionSignature::create([
            'video_session_id' => $this->sessionId,
            'contract_id' => $this->contractId,
            'user_id' => Auth::id(),
            'signature_image_path' => $filename,
            'ip_address' => request()->ip(),
            'signed_at' => now(),
        ]);

        SignatureRequestedDuringCall::dispatch(
            VideoSession::find($this->sessionId),
            $contract,
            Auth::user()
        );

        $this->dispatch('signature-captured');
    }

    public function render()
    {
        return view('livewire.video.in-call-signature-pad');
    }
}
