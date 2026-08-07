<?php

namespace App\Jobs;

use App\Models\ContractSignature;
use App\Models\VideoSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ArchiveVideoSession implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly VideoSession $session) {}

    /**
     * Ensure the session is fully closed and all in-call signatures are
     * persisted.  Any signatures that were captured but not yet committed
     * to their parent Contract are linked here so nothing is lost after
     * the WebRTC room is torn down.
     */
    public function handle(): void
    {
        $session = $this->session->load(['signatures.contract', 'signatures.user']);

        // Guarantee ended_at and status are set.
        $session->updateQuietly([
            'status' => 'ended',
            'ended_at' => $session->ended_at ?? now(),
        ]);

        // For each in-call signature, copy it to ContractSignatures if not already recorded.
        foreach ($session->signatures as $videoSig) {
            if (! $videoSig->contract_id) {
                continue;
            }

            $alreadyExists = ContractSignature::where('contract_id', $videoSig->contract_id)
                ->where('user_id', $videoSig->user_id)
                ->exists();

            if (! $alreadyExists) {
                ContractSignature::create([
                    'contract_id' => $videoSig->contract_id,
                    'user_id' => $videoSig->user_id,
                    'signature_image_path' => $videoSig->signature_image_path,
                    'ip_address' => null,
                    'signed_at' => $videoSig->signed_at ?? now(),
                ]);

                Log::info('ArchiveVideoSession: promoted in-call signature for user '
                    .$videoSig->user_id.' on contract '.$videoSig->contract_id);
            }
        }

        Log::info('ArchiveVideoSession: session '.$session->id.' archived.');
    }
}
