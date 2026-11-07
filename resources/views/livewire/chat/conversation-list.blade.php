<div class="cl" @if($polling) wire:poll.5s.visible @endif>
    <div class="cl-search">
        <input class="input" aria-label="Search conversations" wire:model.live.debounce.300ms="search" type="search" placeholder="Search conversations">
    </div>
    <div class="cl-head">
        <h2>Conversations</h2>
        @can('create', App\Models\Conversation::class)
            <button type="button" wire:click="$dispatch('open-new-conversation')" class="btn btn-sm"><x-icon name="plus" size="sm" /><span>New</span></button>
        @endcan
    </div>
    <ul class="cl-items">
        @forelse($conversations as $conversation)
            @php($label = $conversation->name ?? 'Direct message')
            <li>
                <a class="cl-item {{ $conversation->unreadCount > 0 ? 'is-unread' : '' }}" href="{{ route('chat.show', $conversation) }}"
                   @if($selectedId === $conversation->id) aria-current="true" @endif>
                    <span class="ini pen-{{ ($conversation->id % 4) + 1 }}" aria-hidden="true">{{ strtoupper(mb_substr($label, 0, 1)) }}</span>
                    <span>
                        <span class="cl-name">{{ $label }}</span>
                        <span class="cl-when">{{ $conversation->last_message_at?->diffForHumans() ?? 'No messages yet' }}</span>
                    </span>
                    @if($conversation->unreadCount > 0)
                        <span class="sq" aria-hidden="true"></span>
                        <span class="sr-only">{{ $conversation->unreadCount }} unread</span>
                    @endif
                </a>
            </li>
        @empty
            <li class="cl-empty">
                <b>No conversations yet.</b>
                Start one with the people in your business.
            </li>
        @endforelse
    </ul>
</div>
