<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Notifications\ContractExpiringSoonNotification;
use App\Notifications\ExternalSignerExpiringSoonNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

#[Signature('dotengage:send-expiration-reminders
    {--dry-run : List what would be sent/expired without sending or updating anything}
    {--days=3 : Remind for contracts expiring within this many days}')]
#[Description('Remind pending signers on soon-to-expire contracts, and mark unsigned contracts past their expiry as expired.')]
class SendContractExpirationReminders extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $remindedContracts = 0;
        $remindedPeople = 0;

        $expiringSoon = Contract::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->get();

        foreach ($expiringSoon as $contract) {
            $signedUserIds = $contract->signatures()->whereNotNull('user_id')->pluck('user_id');
            $pendingUsers = $contract->team->allUsers()->reject(
                fn ($user) => $signedUserIds->contains($user->id)
            );

            foreach ($pendingUsers as $user) {
                if (! $dryRun) {
                    $user->notify(new ContractExpiringSoonNotification($contract));
                }
                $remindedPeople++;
            }

            // Only remind external signers whose turn it currently is --
            // reminding someone further down an ordered queue would just
            // confuse them, since they can't act yet anyway.
            $pendingExternal = $contract->externalSigners()
                ->where('status', '!=', 'signed')
                ->get()
                ->filter(fn ($signer) => ! $signer->isExpired() && $signer->isSignable());

            foreach ($pendingExternal as $signer) {
                if (! $dryRun) {
                    $signedUrl = URL::temporarySignedRoute(
                        'external.contracts.show',
                        $signer->expires_at,
                        ['signer' => $signer->id],
                    );

                    Notification::route('mail', $signer->email)
                        ->notify(new ExternalSignerExpiringSoonNotification($contract, $signer, $signedUrl));
                }
                $remindedPeople++;
            }

            if ($pendingUsers->isNotEmpty() || $pendingExternal->isNotEmpty()) {
                $remindedContracts++;
            }
        }

        $this->info("Reminded {$remindedPeople} pending signer(s) across {$remindedContracts} contract(s) expiring within {$days} day(s).");

        // Unsigned contracts already past their expiry can no longer be
        // signed -- flag them so they stop showing up as actionable.
        $expiredQuery = Contract::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        $expiredCount = $dryRun ? $expiredQuery->count() : $expiredQuery->update(['status' => 'expired']);

        $this->info(($dryRun ? '[dry-run] Would mark ' : 'Marked ').$expiredCount.' contract(s) as expired.');

        if (! $dryRun) {
            Log::info("SendContractExpirationReminders: reminded {$remindedPeople} signer(s) across {$remindedContracts} contract(s), marked {$expiredCount} contract(s) expired.");
        }

        return self::SUCCESS;
    }
}
