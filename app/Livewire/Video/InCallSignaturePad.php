<?php

namespace App\Livewire\Video;

use App\Events\SignatureRequestedDuringCall;
use App\Models\Contract;
use App\Models\VideoSession;
use App\Models\VideoSessionSignature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InCallSignaturePad extends Component
{
    /** Largest accepted signature image (decoded bytes). */
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    // Locked: the browser can never point the pad at another session or contract.
    #[Locked]
    public int $sessionId;

    #[Locked]
    public int $contractId;

    public function mount(int $sessionId, int $contractId): void
    {
        $this->sessionId = $sessionId;
        $this->contractId = $contractId;

        $session = $this->sessionForContract();
        $this->authorize('join', $session);
    }

    public function sign(string $signatureData): void
    {
        $session = $this->sessionForContract();
        $contract = Contract::findOrFail($this->contractId);

        // The session must be live and the signer allowed to sign in it, and the
        // contract itself must be signable by them.
        $this->authorize('sign', $session);
        $this->authorize('sign', $contract);

        $data = preg_replace('/^data:image\/png;base64,/', '', $signatureData);
        $decoded = base64_decode($data, true);

        abort_if(
            $decoded === false
                || strlen($decoded) > self::MAX_IMAGE_BYTES
                || ! str_starts_with($decoded, "\x89PNG\r\n\x1a\n"),
            422,
            'The signature must be a PNG image.'
        );
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
            $session,
            $contract,
            Auth::user()
        );

        $this->dispatch('signature-captured');
    }

    public function render()
    {
        return view('livewire.video.in-call-signature-pad');
    }

    /** The session, refusing any pairing of a session with a contract it was not opened for. */
    private function sessionForContract(): VideoSession
    {
        $session = VideoSession::findOrFail($this->sessionId);

        abort_unless((int) $session->contract_id === $this->contractId, 403);

        return $session;
    }
}
