<?php

namespace App\Livewire\Video;

use App\Models\Contract;
use App\Models\VideoSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class SessionLauncher extends Component
{
    public ?int $contractId = null;
    public string $joinRoomId = '';

    public function create(): void
    {
        $this->authorize('create', VideoSession::class);

        $session = VideoSession::create([
            'team_id'      => Auth::user()->currentTeam->id,
            'initiated_by' => Auth::id(),
            'room_id'      => Str::uuid()->toString(),
            'status'       => 'active',
            'contract_id'  => $this->contractId ?: null,
            'started_at'   => now(),
        ]);

        $this->redirect(route('video.room', ['room' => $session->room_id]));
    }

    public function join(): void
    {
        $this->validate(['joinRoomId' => 'required|string']);

        $session = VideoSession::where('room_id', $this->joinRoomId)->firstOrFail();
        $this->authorize('join', $session);

        $this->redirect(route('video.room', ['room' => $session->room_id]));
    }

    public function render()
    {
        // HasTeamScope on Contract already scopes this to the current team.
        $teamContracts = Contract::whereIn('status', ['draft', 'pending'])
            ->latest()->get();

        return view('livewire.video.session-launcher', compact('teamContracts'));
    }
}
