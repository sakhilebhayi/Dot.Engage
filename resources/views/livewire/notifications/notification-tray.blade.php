<div x-data="{ open: false }" @toggle-notification-tray.window="open = !open"
     class="relative" @click.outside="open = false">
    <div x-show="open" x-transition
         class="absolute right-0 mt-2 w-80 bg-white shadow-lg rounded-xl overflow-hidden z-50 border border-gray-200">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
            @if($notifications->where('read_at', null)->count())
                <button wire:click="markAllRead" class="text-xs text-indigo-600 hover:underline">Mark all read</button>
            @endif
        </div>
        <ul class="max-h-80 overflow-y-auto divide-y divide-gray-100">
            @forelse($notifications as $notification)
                <li wire:click="markRead('{{ $notification->id }}')" role="button" tabindex="0"
                    wire:keydown.enter="markRead('{{ $notification->id }}')" wire:keydown.space.prevent="markRead('{{ $notification->id }}')"
                    class="px-4 py-3 cursor-pointer hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 {{ is_null($notification->read_at) ? 'bg-indigo-50' : '' }}">
                    <p class="text-sm text-gray-900">{{ $notification->data['message'] ?? 'New notification' }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $notification->created_at->diffForHumans() }}</p>
                </li>
            @empty
                <li class="px-4 py-4 text-sm text-gray-500 text-center">No notifications yet.</li>
            @endforelse
        </ul>
        @if($notifications->count())
            <div class="px-4 py-2 border-t border-gray-200 text-center">
                <a href="{{ route('notifications.index') }}" class="text-xs text-indigo-600 hover:underline">View all</a>
            </div>
        @endif
    </div>
</div>
