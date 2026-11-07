<div>
    @php($unread = $notifications->whereNull('read_at')->count())
    <section aria-labelledby="h-latest">
        <div class="section-head">
            <h2 id="h-latest">Latest <span class="n">{{ $notifications->count() }}</span></h2>
            @if($unread)
                <button type="button" wire:click="markAllRead" class="btn btn-quiet btn-sm"><span>Mark all read</span></button>
            @endif
        </div>

        @if($notifications->isNotEmpty())
            <ul class="nt-list">
                @foreach($notifications as $notification)
                    @php
                        $isUnread = is_null($notification->read_at);
                        $target = $notification->data['url'] ?? (isset($notification->data['conversation_id']) ? route('chat.show', $notification->data['conversation_id']) : null);
                    @endphp
                    <li class="{{ $isUnread ? 'is-unread' : 'is-read' }}" wire:key="n-{{ $notification->id }}">
                        @if($isUnread)<span class="sq" aria-hidden="true"></span>@else<span class="sq-slot" aria-hidden="true"></span>@endif
                        <div>
                            @if($target)
                                <a class="nt-msg" href="{{ $target }}">@if($isUnread)<span class="sr-only">Unread: </span>@endif{{ $notification->data['message'] ?? 'Something changed' }}</a>
                            @else
                                <span class="nt-msg">@if($isUnread)<span class="sr-only">Unread: </span>@endif{{ $notification->data['message'] ?? 'Something changed' }}</span>
                            @endif
                            <div class="nt-meta">
                                <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                                @if(!empty($notification->data['engagement_reference']))<span class="fig">{{ $notification->data['engagement_reference'] }}</span>@endif
                            </div>
                        </div>
                        <div class="nt-end">
                            @if($isUnread)
                                <button type="button" wire:click="markRead('{{ $notification->id }}')" class="btn btn-quiet btn-sm"><span>Mark read</span></button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="empty"><b>Nothing yet.</b>When someone comments, asks for a signature or messages you, it shows up here.</p>
        @endif
    </section>
</div>
