<div class="ct" @if($polling) wire:poll.5s.visible="refreshFromPoll" @endif>
    <div class="ct-head">
        <a class="ct-back" href="{{ route('chat.index') }}"><x-icon name="back" size="sm" /> Conversations</a>
        <div>
            <h2>{{ $conversation->name ?? 'Direct message' }}</h2>
            <p class="sub">{{ $conversation->participants->count() }} {{ str('person')->plural($conversation->participants->count()) }}</p>
        </div>
    </div>

    <div class="ct-scroll" id="message-thread" tabindex="0" aria-label="Messages"
         x-data="{ stick: true }"
         x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight); new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true })"
         x-on:scroll.passive="stick = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 80">
        @if($hasMorePages)
            <div class="ct-more">
                <button type="button" wire:click="loadMore" class="linkbtn">Load older messages</button>
            </div>
        @endif

        @forelse($messages as $message)
            @php($mine = $message->user_id === auth()->id())
            <article class="msg pen pen-{{ ($message->user_id % 4) + 1 }}">
                <span class="ini" aria-hidden="true">{{ strtoupper(mb_substr($message->sender?->name ?? '?', 0, 1)) }}</span>
                <div>
                    <div class="top">
                        <span class="by">{{ $mine ? 'You' : ($message->sender?->name ?? 'Unknown') }}</span>
                        <time class="when" datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('j M H:i') }}</time>
                    </div>
                    @if($message->body)<div class="text">{{ $message->body }}</div>@endif
                    @foreach($message->attachments as $attachment)
                        <a class="att" href="{{ Storage::url($attachment->file_path) }}" target="_blank" rel="noopener">
                            <x-icon name="doc" size="sm" /> {{ $attachment->original_filename }}
                        </a>
                    @endforeach
                </div>
            </article>
        @empty
            <p class="ct-quiet">Nothing has been said here yet. Write the first message below.</p>
        @endforelse
    </div>

    @can('sendMessage', $conversation)
        <div class="ct-foot">
            <livewire:chat.message-composer :conversationId="$conversationId" />
        </div>
    @else
        <p class="ct-ro">Your role is read-only here, so you can read this conversation but not reply.</p>
    @endcan
</div>
