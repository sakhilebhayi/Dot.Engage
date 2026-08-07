<?php

namespace App\Listeners;

use App\Events\ContractSigned;
use App\Notifications\ContractSignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyContractSigned implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ContractSigned $event): void
    {
        $contract = $event->contract;
        $signature = $event->signature;

        // Notify the contract creator.
        $contract->creator->notify(
            new ContractSignedNotification($contract, $signature)
        );

        // Also notify other team members who haven't signed yet.
        $contract->team->allUsers()
            ->reject(fn ($u) => $u->id === $contract->created_by)
            ->reject(fn ($u) => $u->id === $signature->user_id)
            ->each(fn ($u) => $u->notify(new ContractSignedNotification($contract, $signature)));
    }
}
