<?php

namespace App\Events;

use App\Models\Contract;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignatureRequestedDuringCall implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly VideoSession $session,
        public readonly Contract $contract,
        public readonly User $signedBy,
    ) {}

    /**
     * Notify all team members watching the session channel.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team.'.$this->session->team_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'signature.requested-during-call';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
            'signed_by_id' => $this->signedBy->id,
            'signed_by_name' => $this->signedBy->name,
        ];
    }
}
