<?php

namespace App\Livewire\Chat;

use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class UnreadBadge extends Component
{
    public int $count = 0;

    public function refresh(): void
    {
        $this->count = $this->getUnreadCount();
    }

    private function getUnreadCount(): int
    {
        return Message::whereHas('conversation.participants', fn ($q) => $q->where('users.id', Auth::id()))
            ->where('user_id', '!=', Auth::id())
            ->whereNull('read_at')
            ->count();
    }

    public function render()
    {
        $this->count = $this->getUnreadCount();

        return view('livewire.chat.unread-badge');
    }
}
