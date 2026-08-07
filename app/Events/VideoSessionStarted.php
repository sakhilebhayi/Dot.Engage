<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoSessionStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly VideoSession $session) {}

    /**
     * Notify the whole team that a session has started.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team.'.$this->session->team_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'video-session.started';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'room_id' => $this->session->room_id,
            'initiated_by' => $this->session->initiated_by,
            'contract_id' => $this->session->contract_id,
            'started_at' => $this->session->started_at?->toIso8601String(),
        ];
    }
}
