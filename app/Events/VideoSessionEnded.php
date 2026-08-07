<?php

namespace App\Events;

use App\Models\VideoSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoSessionEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly VideoSession $session) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team.'.$this->session->team_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'video-session.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'room_id' => $this->session->room_id,
            'ended_at' => $this->session->ended_at?->toIso8601String(),
        ];
    }
}
