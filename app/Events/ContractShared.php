<?php

namespace App\Events;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractShared implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly User $sharedWith,
    ) {}

    /**
     * Broadcast to the recipient's private user channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->sharedWith->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'contract.shared';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
            'shared_with_id' => $this->sharedWith->id,
        ];
    }
}
