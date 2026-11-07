<div wire:poll.10s="refresh">
    @if($count > 0)
        <span class="state is-ink"><span class="sr-only">Unread messages: </span><span class="fig">{{ $count > 99 ? '99+' : $count }}</span></span>
    @endif
</div>
