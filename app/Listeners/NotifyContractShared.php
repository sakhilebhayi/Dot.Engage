<?php

namespace App\Listeners;

use App\Events\ContractShared;
use App\Notifications\ContractSharedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyContractShared implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ContractShared $event): void
    {
        $event->sharedWith->notify(
            new ContractSharedNotification($event->contract, $event->sharedById)
        );
    }
}
