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
     *
     * Must be "App.Models.User.{id}" -- the only per-user private channel
     * routes/channels.php actually registers (it's also what Laravel's own
     * database-notification broadcasting and NotificationBell rely on). A
     * bare "user.{id}" channel was never registered, so nothing could ever
     * subscribe to or authorize this broadcast.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->sharedWith->id),
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
