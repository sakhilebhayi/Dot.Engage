<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Realtime;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ConversationThread extends Component
{
    // Locked: the browser can never swap in another conversation or page count;
    // only the server-side actions below change these.
    #[Locked]
    public int $conversationId;

    #[Locked]
    public int $page = 1;

    #[Locked]
    public bool $hasMorePages = false;

    /** Upper bound on loaded pages (20 messages each), so one request can't ask for the whole table. */
    private const MAX_PAGES = 50;

    #[On('conversation-selected')]
    public function switchConversation(int $conversationId): void
    {
        $this->authorize('view', Conversation::findOrFail($conversationId));
        $this->conversationId = $conversationId;
        $this->page = 1;
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
        $this->page = min($this->page + 1, self::MAX_PAGES);
    }

    /**
     * Polling fallback for hosts with no websocket server: the render that
     * follows this call loads new messages, so all that is left to do is
     * mark the ones just shown as read.
     */
    public function refreshFromPoll(): void
    {
        $this->markAsRead();
    }

    private function markAsRead(): void
    {
        $this->authorize('view', Conversation::findOrFail($this->conversationId));

        Message::where('conversation_id', $this->conversationId)
            ->where('user_id', '!=', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render()
    {
        $conversation = Conversation::with('participants')
            ->findOrFail($this->conversationId);

        // Checked on every render, not only at mount: membership can end mid-session.
        $this->authorize('view', $conversation);

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
            'polling' => ! Realtime::active(),
        ]);
    }
}
