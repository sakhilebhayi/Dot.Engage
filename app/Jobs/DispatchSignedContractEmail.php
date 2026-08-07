<?php

namespace App\Jobs;

use App\Mail\SignedContractMail;
use App\Models\Contract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DispatchSignedContractEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public readonly Contract $contract) {}

    /**
     * Send the signed contract email to every party who signed,
     * plus the contract creator if they are not already a signer.
     */
    public function handle(): void
    {
        $contract = $this->contract->load(['creator', 'signatures.user', 'versions']);
        $signedVersion = $contract->versions()->orderByDesc('version_number')->first();

        if (! $signedVersion) {
            Log::warning('DispatchSignedContractEmail: no version found for contract '.$contract->id);

            return;
        }

        // Collect unique recipients: all signers + the creator.
        $recipients = $contract->signatures
            ->map(fn ($s) => $s->user)
            ->push($contract->creator)
            ->unique('id');

        foreach ($recipients as $user) {
            Mail::to($user->email)->queue(
                new SignedContractMail($contract, $signedVersion, $user)
            );
        }

        Log::info('DispatchSignedContractEmail: queued '.$recipients->count().' emails for contract '.$contract->id);
    }
}
