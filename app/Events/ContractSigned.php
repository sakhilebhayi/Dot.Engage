<?php

namespace App\Events;

use App\Models\Contract;
use App\Models\ContractSignature;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractSigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly ContractSignature $signature,
    ) {}

    /**
     * Broadcast to the team channel so all members see the update live.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team.'.$this->contract->team_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'contract.signed';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
            'contract_status' => $this->contract->status,
            'signed_by_id' => $this->signature->user_id,
        ];
    }
}
