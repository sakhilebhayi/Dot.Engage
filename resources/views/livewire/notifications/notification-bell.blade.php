<div wire:poll.15s="refresh">
    <button type="button" wire:click="$dispatch('toggle-notification-tray')" class="iconbtn" aria-label="Notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}">
        <x-icon name="bell" />
        @if($unreadCount > 0)
            <span class="count">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </button>
</div>
