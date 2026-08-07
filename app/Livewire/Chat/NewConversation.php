<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NewConversation extends Component
{
    public bool $show = false;

    public string $name = '';

    public bool $isGroup = false;

    public array $selectedUsers = [];

    public function create(): void
    {
        $this->validate([
            'selectedUsers' => 'required|array|min:1',
            'selectedUsers.*' => 'integer|exists:users,id',
            'name' => 'nullable|string|max:255',
        ]);

        $team = Auth::user()->currentTeam;

        $conversation = Conversation::create([
            'team_id' => $team->id,
            'name' => $this->name ?: null,
            'is_group' => $this->isGroup,
        ]);

        $allParticipants = array_unique(array_merge([Auth::id()], $this->selectedUsers));
        foreach ($allParticipants as $userId) {
            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
            ]);
        }

        $this->reset(['name', 'isGroup', 'selectedUsers', 'show']);
        $this->dispatch('conversation-selected', conversationId: $conversation->id);
    }

    public function render()
    {
        $teamMembers = Auth::user()->currentTeam
            ? Auth::user()->currentTeam->allUsers()->where('id', '!=', Auth::id())
            : collect();

        return view('livewire.chat.new-conversation', compact('teamMembers'));
    }
}
