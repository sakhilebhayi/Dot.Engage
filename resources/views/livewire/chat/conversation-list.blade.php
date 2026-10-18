<div class="flex flex-col h-full" @if($polling) wire:poll.5s.visible @endif>
    <div class="p-3 border-b border-gray-200">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search conversations…"
               class="w-full rounded-md border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
    </div>
    <div class="flex justify-between items-center px-3 py-2">
        <span class="text-xs font-semibold text-gray-500 uppercase">Messages</span>
        <button wire:click="$dispatch('open-new-conversation')" class="text-xs text-indigo-600 hover:underline">+ New</button>
    </div>
    <ul class="flex-1 overflow-y-auto divide-y divide-gray-100">
        @forelse($conversations as $conversation)
            <li wire:click="select({{ $conversation->id }})"
                class="px-3 py-3 cursor-pointer hover:bg-indigo-50 {{ $selectedId === $conversation->id ? 'bg-indigo-50' : '' }}">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ $conversation->name ?? 'Direct Message' }}</p>
                    @if($conversation->unreadCount > 0)
                        <span class="ml-2 inline-flex items-center justify-center w-5 h-5 rounded-full bg-indigo-600 text-white text-xs">
                            {{ $conversation->unreadCount }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-gray-400 mt-0.5">{{ $conversation->last_message_at?->diffForHumans() ?? '' }}</p>
            </li>
        @empty
            <li class="px-3 py-4 text-sm text-gray-500 text-center">No conversations yet.</li>
        @endforelse
    </ul>
</div>
