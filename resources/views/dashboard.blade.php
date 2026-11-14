<x-app-layout title="Desk">
    @php
        // Nothing at all to show: no engagement of this business, nothing waiting on this person, no meeting booked.
        $blank = ! $latest && $waiting->isEmpty() && $upcoming->isEmpty();
        $stageNow = $latest?->state->stage();
        $allDone = $latest?->state === \App\Enums\EngagementState::Completed;
    @endphp

    <div @class(['land', 'is-alone' => $blank])>
        <div class="land-top">
            <span class="land-logo">
                <img class="logo-on-light" src="{{ asset('images/logo.png') }}" alt="Dot.Engage" width="104" height="104">
                <img class="logo-on-dark" src="{{ asset('images/logo-light.png') }}" alt="Dot.Engage" width="104" height="104">
            </span>
        </div>

        <div class="land-main">
            <div class="land-say">
                <h1>Every agreement, from first talk to signature.</h1>
                <p class="land-welcome">Welcome to Dot.Engage, <b>{{ $firstName }}</b>. You are at the desk of <b>{{ $team->name }}</b>.</p>
                <p class="land-lede">Invite the other party, talk the document through, agree the changes and sign. What was said stays beside the clause it concerns.</p>
                <div class="land-act">
                    <a class="btn btn-lead" href="{{ $primary['url'] }}" data-primary-action><span>{{ $primary['label'] }}</span></a>
                    @if ($canStart)<a href="{{ route('engagements.index') }}">All engagements</a>@endif
                    <a href="{{ route('meetings.index') }}">Meetings</a>
                    <a href="{{ route('agreements.index') }}">Agreements</a>
                </div>
            </div>

            <section class="land-sheet" aria-labelledby="h-latest">
                @if ($latest)
                    <h2 class="sr-only" id="h-latest">Most recently changed engagement</h2>
                    <div class="ls-top">
                        <span class="fig">{{ $latest->reference }}</span>
                        <x-state :state="$latest->state" />
                    </div>
                    <p class="ls-title"><a href="{{ route('engagements.show', $latest) }}">{{ $latest->title }}</a></p>
                    @if ($latest->parties->isNotEmpty())
                        <div class="parties" aria-label="Parties">
                            @foreach ($latest->parties as $party)
                                @if (! $loop->first)<span class="sep" aria-hidden="true">with</span>@endif
                                <span class="p pen pen-{{ ($loop->index % 4) + 1 }}"><span class="ini ini-sm">{{ strtoupper(mb_substr($party->name, 0, 1)) }}</span> <span class="by">{{ $party->name }}</span></span>
                            @endforeach
                        </div>
                    @endif
                    @if ($stageNow !== null)
                        <ol class="ls-rail" aria-label="Progress">
                            @foreach ($stages as $i => $name)
                                @php($status = $allDone || $i < $stageNow ? 'done' : ($i === $stageNow ? 'now' : 'later'))
                                <li class="is-{{ $status }}" @if ($status === 'now') aria-current="step" @endif>{{ $name }}</li>
                            @endforeach
                        </ol>
                    @endif
                    <p class="ls-next">{{ $latest->state->nextStep() }}</p>
                    <p class="ls-foot">
                        <span>Last changed <time data-local datetime="{{ $latest->updated_at?->toIso8601String() }}">{{ $latest->updated_at?->format('j M H:i') }}</time></span>
                        <a href="{{ route('engagements.show', $latest) }}">Open the workspace</a>
                    </p>
                @else
                    <h2 class="ls-title" id="h-latest">No engagements yet.</h2>
                    <p class="ls-next">
                        @if ($canStart)
                            An engagement is one business conversation about one agreement. Name it and the other party, then add the document and the people. It moves through these six stages.
                        @else
                            {{ $team->name }} has not started an engagement. An administrator or editor of the business starts one, and it then shows here with its stage.
                        @endif
                    </p>
                    <ol class="ls-rail" aria-label="The six stages">
                        @foreach ($stages as $name)<li class="is-later">{{ $name }}</li>@endforeach
                    </ol>
                    @if ($blank)<p class="ls-foot"><span>Nothing is waiting on you and no meeting is booked.</span></p>@endif
                @endif
            </section>
        </div>
    </div>

    @unless ($blank)
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
    @endunless
</x-app-layout>
