<x-workspace :engagement="$engagement" :access="$access" :ws="$ws" tab="discussion">
    <div class="section-head" style="margin-top:0"><h2 id="discussion">Discussion <span class="n">{{ $messages->where('is_system', false)->count() }}</span></h2></div>
    <p class="muted" style="margin:10px 0 0;max-width:62ch">Everything said here stays with the engagement. Notes about a specific clause belong on the agreement itself, so they stay attached to the right place.</p>

    <div class="thread" aria-live="polite">
        @forelse ($messages as $m)
            @php($p = $m->participant)
            @if ($m->is_system)
                <div class="msg is-system"><span>{{ $m->body }}</span></div>
            @else
                <article class="msg pen pen-{{ $ws->penFor($p?->party_id) }}">
                    <span class="ini">{{ strtoupper(mb_substr($m->author_name, 0, 1)) }}</span>
                    <div>
                        <div class="top"><span class="by">{{ $m->author_name }}</span><time class="when" data-local datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->format('j M H:i') }}</time></div>
                        <div class="text">{!! preg_replace('/(^|\s)@([\p{L}0-9._-]+)/u', '$1<span class="mention">@$2</span>', e($m->body)) !!}</div>
                    </div>
                </article>
            @endif
        @empty
            <p class="empty"><b>Nothing said yet.</b>Open with what you want out of this agreement. Everyone invited can see it.</p>
        @endforelse
        <span id="latest"></span>
    </div>

    @if ($access->can('message'))
        <form method="post" action="{{ route('engagements.post', $engagement) }}" style="max-width:760px;margin-top:22px">
            @csrf
            <div class="field">
                <label for="body">Write to everyone on this engagement</label>
                <textarea class="textarea" id="body" name="body" required maxlength="4000" placeholder="Use @name to mention someone"></textarea>
                @error('body')<span class="err">{{ $message }}</span>@enderror
            </div>
            <button class="btn" type="submit"><span>Post</span></button>
        </form>
    @else
        <p class="muted" style="margin-top:22px">This engagement is closed, so the discussion is read-only.</p>
    @endif
</x-workspace>
