<x-app-layout title="Desk">
    <div class="page-head">
        <div>
            <h1>Desk</h1>
            <p class="lede">{{ $team->name }}. What needs you, what is coming up, and where each agreement stands.</p>
        </div>
        <div class="page-actions">
            <a class="btn" href="{{ route('engagements.create') }}"><span>New engagement</span></a>
            <a class="btn btn-quiet" href="{{ route('meetings.index') }}"><span>Meetings</span></a>
        </div>
    </div>

    <section aria-labelledby="h-waiting">
        <div class="section-head"><h2 id="h-waiting">Waiting for you <span class="n">{{ $waiting->count() }}</span></h2></div>
        @if ($waiting->isNotEmpty())
            <ul class="rows">
                @foreach ($waiting as $item)
                    <li style="--cols: 1fr auto 130px">
                        <div>
                            <a class="row-title" href="{{ $item['url'] }}">{{ $item['what'] }}</a>
                            <div class="row-sub">{{ $item['engagement']->title }} <span class="fig">{{ $item['engagement']->reference }}</span></div>
                        </div>
                        <span class="state is-warn">{{ $item['engagement']->state->label() }}</span>
                        <time class="row-end fig" data-local datetime="{{ optional($item['since'])->toIso8601String() }}">{{ optional($item['since'])->format('j M H:i') }}</time>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="empty"><b>You are clear.</b>Nothing is waiting on you. Approvals, signatures and changes to review will show up here.</p>
        @endif
    </section>

    <section class="section" aria-labelledby="h-meet">
        <div class="section-head"><h2 id="h-meet">Meetings <span class="n">{{ $upcoming->count() }}</span></h2><a href="{{ route('meetings.index') }}">All meetings</a></div>
        @if ($upcoming->isNotEmpty())
            <ul class="rows">
                @foreach ($upcoming as $m)
                    <li style="--cols: 1fr auto 130px">
                        <div><a class="row-title" href="{{ route('meetings.room', $m->room_key) }}">{{ $m->title }}</a><div class="row-sub">{{ $m->engagement->title }}</div></div>
                        @if ($m->status === 'live')<span class="state is-live">Live now</span>@else<span class="state">Scheduled</span>@endif
                        <time class="row-end fig" data-local datetime="{{ optional($m->scheduled_for)->toIso8601String() }}">{{ optional($m->scheduled_for)->format('j M H:i') }}</time>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="empty">No meetings booked. Start one from inside an engagement so the agreement is one click away.</p>
        @endif
    </section>

    <section class="section" aria-labelledby="h-active">
        <div class="section-head">
            <h2 id="h-active">Active engagements <span class="n">{{ $activeCount }}</span></h2>
            <a href="{{ route('engagements.index') }}">All engagements</a>
        </div>
        @if ($active->isNotEmpty())
            <ul class="rows">
                @foreach ($active as $e)
                    <li style="--cols: 1fr minmax(170px,auto) 130px">
                        <div>
                            <a class="row-title" href="{{ route('engagements.show', $e) }}">{{ $e->title }}</a>
                            <div class="row-sub">{{ $e->state->nextStep() }}</div>
                        </div>
                        <x-state :state="$e->state" />
                        <span class="row-end fig">{{ $e->reference }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="empty"><b>No engagements yet.</b>An engagement is a business conversation about one agreement: invite the other party, discuss, agree changes, sign. Start with the other party and the document.</p>
        @endif
    </section>

    <div class="dash-split">
        <section class="section" aria-labelledby="h-sign">
            <div class="section-head"><h2 id="h-sign">Out for signature <span class="n">{{ $awaitingSignature->count() }}</span></h2></div>
            @if ($awaitingSignature->isNotEmpty())
                <ul class="rows">
                    @foreach ($awaitingSignature as $env)
                        <li><div><a class="row-title" href="{{ route('engagements.signing', $env->engagement) }}">{{ $env->engagement->title }}</a><div class="row-sub">Sent <time data-local="day" datetime="{{ $env->sent_at?->toIso8601String() }}">{{ $env->sent_at?->format('j M Y') }}</time></div></div></li>
                    @endforeach
                </ul>
            @else
                <p class="empty">Nothing is out for signature.</p>
            @endif
        </section>

        <section class="section" aria-labelledby="h-changes">
            <div class="section-head"><h2 id="h-changes">Changes to review <span class="n">{{ $outstandingChanges }}</span></h2></div>
            <p class="empty">{{ $outstandingChanges === 0 ? 'No proposed changes are waiting.' : $outstandingChanges.' proposed '.str('change')->plural($outstandingChanges).' across your engagements. Open an engagement in negotiation to review them.' }}</p>
        </section>
    </div>

    <div class="dash-split">
        <section class="section" aria-labelledby="h-done">
            <div class="section-head"><h2 id="h-done">Recently completed</h2><a href="{{ route('agreements.index') }}">Agreements</a></div>
            @if ($completed->isNotEmpty())
                <ul class="rows">
                    @foreach ($completed as $e)
                        <li><div><a class="row-title" href="{{ route('engagements.show', $e) }}">{{ $e->title }}</a><div class="row-sub fig">{{ $e->reference }}</div></div><time class="row-end" data-local="day" datetime="{{ $e->completed_at?->toIso8601String() }}">{{ $e->completed_at?->format('j M Y') }}</time></li>
                    @endforeach
                </ul>
            @else
                <p class="empty">No completed agreements yet.</p>
            @endif
        </section>

        <section class="section" aria-labelledby="h-closed">
            <div class="section-head"><h2 id="h-closed">Closed without agreement</h2></div>
            @if ($closed->isNotEmpty())
                <ul class="rows">
                    @foreach ($closed as $e)
                        <li><div><a class="row-title" href="{{ route('engagements.show', $e) }}">{{ $e->title }}</a><div class="row-sub fig">{{ $e->reference }}</div></div><x-state :state="$e->state" /></li>
                    @endforeach
                </ul>
            @else
                <p class="empty">No declined, cancelled or expired engagements.</p>
            @endif
        </section>
    </div>
</x-app-layout>
