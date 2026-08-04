<?php

namespace App\Livewire\Dashboard;

use App\Models\Contract;
use App\Models\Conversation;
use App\Models\VideoSession;
use Livewire\Component;

class TeamDashboard extends Component
{
    public function render()
    {
        // Reads below rely on HasTeamScope (Contract/Conversation/VideoSession
        // all apply it) to scope to Auth::user()->currentTeam automatically --
        // no explicit team_id filter needed here anymore.
        return view('livewire.dashboard.team-dashboard', [
            'totalContracts'      => Contract::count(),
            'pendingContracts'    => Contract::where('status', 'pending')->count(),
            'recentContracts'     => Contract::latest()->limit(5)->get(),
            'activeConversations' => Conversation::latest('last_message_at')->limit(5)->get(),
            'activeSessions'      => VideoSession::where('status', 'active')->limit(5)->get(),
        ]);
    }
}
