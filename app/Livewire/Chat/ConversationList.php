<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ConversationList extends Component
{
    public string $search = '';

    public ?int $selectedId = null;

    public function select(int $conversationId): void
    {
        $this->selectedId = $conversationId;
        $this->dispatch('conversation-selected', conversationId: $conversationId);
    }

    public function render()
    {
        $userId = Auth::id();

        $conversations = Conversation::whereHas('participants', fn ($q) => $q->where('users.id', $userId))
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->with(['participants' => fn ($q) => $q->where('users.id', $userId)])
            ->withCount(['messages as unreadCount' => fn ($q) => $q->where('read_at', null)->where('user_id', '!=', $userId)])
            ->latest('last_message_at')
            ->get();

        return view('livewire.chat.conversation-list', compact('conversations'));
    }
}
