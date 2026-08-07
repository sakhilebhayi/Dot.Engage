<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationThread extends Component
{
    public int $conversationId;

    public int $page = 1;

    public bool $hasMorePages = false;

    #[On('conversation-selected')]
    public function switchConversation(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->page = 1;
        $this->authorize('view', Conversation::findOrFail($conversationId));
        $this->markAsRead();
    }

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->authorize('view', Conversation::findOrFail($conversationId));
        $this->markAsRead();
    }

    #[On('echo-private:conversation.{conversationId},message.sent')]
    public function messageReceived(array $data): void
    {
        $this->markAsRead();
    }

    public function loadMore(): void
    {
        $this->page++;
    }

    private function markAsRead(): void
    {
        Message::where('conversation_id', $this->conversationId)
            ->where('user_id', '!=', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render()
    {
        $conversation = Conversation::with('participants')
            ->findOrFail($this->conversationId);

        $perPage = 20;
        $paginator = Message::with(['sender', 'attachments'])
            ->where('conversation_id', $this->conversationId)
            ->latest()
            ->paginate($perPage * $this->page);

        $messages = $paginator->items();
        $this->hasMorePages = $paginator->hasMorePages();

        return view('livewire.chat.conversation-thread', [
            'conversation' => $conversation,
            'messages' => array_reverse($messages),
        ]);
    }
}
