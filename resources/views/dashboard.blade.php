<x-app-layout title="Desk">
    @php
        // Nothing at all to show: no engagement of this business, nothing waiting on this person, no meeting booked.
        $blank = ! $latest && $waiting->isEmpty() && $upcoming->isEmpty();
        $stageNow = $selected?->state->stage();
        $allDone = $selected?->state === \App\Enums\EngagementState::Completed;
        $reselect = request()->query('e') !== null && $selected && request()->query('e') === $selected->reference;
    @endphp

    <div @class(['desk', 'is-alone' => $blank, 'is-reselect' => $reselect])>
        <section class="desk-arrive" aria-labelledby="h-state">
            <div class="desk-arrive-in">
                <h1 id="h-state">{{ $headline }}</h1>
                <p class="desk-welcome">Welcome to Dot.Engage, <b>{{ $firstName }}</b>. You are at the desk of <b>{{ $team->name }}</b>.</p>
            </div>
            <div class="desk-arrive-act">
                <a class="btn btn-lead" href="{{ $primary['url'] }}" data-primary-action><span>{{ $primary['label'] }}</span></a>
                @if ($canStart && ! $blank)<a href="{{ route('engagements.index') }}">All engagements</a>@endif
            </div>
            {{-- The bottom edge of the band is the six-stage rail of the engagement on the sheet: what is done is inked, the stage it is at is thicker. --}}
            <div class="desk-line" aria-hidden="true">
                @foreach ($stages as $i => $name)
                    <i class="{{ $stageNow === null ? '' : ($allDone || $i < $stageNow ? 'd' : ($i === $stageNow ? 'n' : '')) }}"></i>
                @endforeach
            </div>
        </section>

        @if ($blank)
            <div class="desk-alone">
                <section class="desk-sheet" aria-labelledby="h-latest">
                    <svg class="desk-art" viewBox="0 0 240 96" width="240" height="96" aria-hidden="true" focusable="false">
                        <rect class="a-sheet" x="1" y="1" width="150" height="94"/>
                        <path class="a-text" d="M14 18h96M14 30h120M14 42h104"/>
                        <rect class="a-hl" x="14" y="52" width="86" height="10"/>
                        <path class="a-text" d="M14 74h112"/>
                        <path class="a-leader" d="M100 57h54"/>
                        <rect class="a-note" x="154" y="40" width="85" height="34"/>
                        <path class="a-text" d="M164 52h56M164 62h40"/>
                    </svg>
                    <h2 class="desk-sheet-title" id="h-latest">No engagements yet.</h2>
                    <p class="desk-sheet-next">
                        @if ($canStart)
                            An engagement is one business conversation about one agreement. Name it and the other party, then add the document and the people. It moves through these six stages.
                        @else
                            {{ $team->name }} has not started an engagement. An administrator or editor of the business starts one, and it then shows here with its stage.
                        @endif
                    </p>
                    <ol class="desk-rail-empty" aria-label="The six stages">
                        @foreach ($stages as $name)<li>{{ $name }}</li>@endforeach
                    </ol>
                    <p class="desk-sheet-foot"><span>Nothing is waiting on you and no meeting is booked.</span></p>
                </section>
            </div>
        @else
            <div class="desk-grid">
                <div class="desk-left">
                    <section class="desk-block" aria-labelledby="h-waiting">
                        <div class="desk-head"><h2 id="h-waiting">Waiting for you<span class="n">{{ $waiting->count() }}</span></h2></div>
                        @if ($waiting->isNotEmpty())
                            <ul class="desk-rows">
                                @foreach ($waiting as $item)
                                    <li>
                                        <div>
                                            <div class="t"><a href="{{ $item['url'] }}">{{ $item['what'] }}</a></div>
                                            <div class="s">{{ $item['engagement']->title }} <span class="fig">{{ $item['engagement']->reference }}</span></div>
                                        </div>
                                        <span class="state is-warn">{{ $item['engagement']->state->label() }}</span>
                                        <time class="e fig" data-local datetime="{{ optional($item['since'])->toIso8601String() }}">{{ optional($item['since'])->format('j M H:i') }}</time>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="desk-empty"><b>You are clear.</b>Nothing is waiting on you. Approvals, signatures and changes to review will show up here.</p>
                        @endif
                    </section>

                    <section class="desk-block" aria-labelledby="h-active">
                        <div class="desk-head">
                            <h2 id="h-active">Active engagements<span class="n">{{ $activeCount }}</span></h2>
                            <a href="{{ route('engagements.index') }}">All engagements</a>
                        </div>
                        @if ($active->isNotEmpty())
                            <table class="desk-ledger">
                                <thead><tr>
                                    <th scope="col" aria-sort="none"><button type="button">Engagement</button></th>
                                    <th scope="col" class="hide-p pty-col">Parties and reference</th>
                                    <th scope="col" aria-sort="none"><button type="button">Stage</button></th>
                                    <th scope="col" class="hide-p">Status</th>
                                    <th scope="col" class="upd hide-p" aria-sort="none"><button type="button">Changed</button></th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($active as $e)
                                        @php($st = $e->state->stage())
                                        <tr @if ($selected && $e->id === $selected->id) aria-current="true" @endif>
                                            <td class="eng"><a href="{{ route('dashboard', ['e' => $e->reference]) }}#selected">{{ $e->title }}</a><span class="stage-sub">{{ $e->state->nextStep() }}</span></td>
                                            <td class="pty hide-p pty-col">{{ $e->parties->pluck('name')->implode(' with ') }}<span class="stage-sub fig">{{ $e->reference }}</span></td>
                                            <td data-sort="{{ $st ?? -1 }}">
                                                <span class="stage-name">
                                                    <span class="stage-rail" aria-hidden="true">@foreach ($stages as $i => $name)<i class="{{ $st === null ? '' : ($i < $st ? 'd' : ($i === $st ? 'n' : '')) }}"></i>@endforeach</span>
                                                    {{ $st === null ? '' : $stages[$st] }}
                                                </span>
                                            </td>
                                            <td class="hide-p"><x-state :state="$e->state" /></td>
                                            <td class="upd hide-p fig" data-sort="{{ $e->updated_at?->toIso8601String() }}"><time data-local datetime="{{ $e->updated_at?->toIso8601String() }}">{{ $e->updated_at?->format('j M H:i') }}</time></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="desk-empty"><b>No engagements yet.</b>An engagement is a business conversation about one agreement: invite the other party, discuss, agree changes, sign. Start with the other party and the document.</p>
                        @endif
                    </section>
                </div>

                <aside id="selected" class="desk-side" aria-labelledby="h-latest">
                    @if ($selected)
                        <section class="desk-sheet">
                            <h2 class="sr-only" id="h-latest">Selected engagement</h2>
                            <div class="desk-sheet-top">
                                <span class="fig">{{ $selected->reference }}</span>
                                <x-state :state="$selected->state" />
                            </div>
                            <p class="desk-sheet-title"><a href="{{ route('engagements.show', $selected) }}">{{ $selected->title }}</a></p>
                            @if ($selected->parties->isNotEmpty())
                                <div class="parties" aria-label="Parties">
                                    @foreach ($selected->parties as $party)
                                        @if (! $loop->first)<span class="sep" aria-hidden="true">with</span>@endif
                                        <span class="p pen pen-{{ ($loop->index % 4) + 1 }}"><span class="ini ini-sm">{{ strtoupper(mb_substr($party->name, 0, 1)) }}</span> <span class="by">{{ $party->name }}</span></span>
                                    @endforeach
                                </div>
                            @endif
                            @if ($sheetStages)
                                <ol class="desk-stages" aria-label="Progress">
                                    @foreach ($sheetStages as $s)
                                        <li class="is-{{ $s['status'] }}" @if ($s['status'] === 'now') aria-current="step" @endif><span class="mk"></span><span class="nm">{{ $s['name'] }}</span><span class="fact">{{ $s['fact'] }}</span></li>
                                    @endforeach
                                </ol>
                            @endif
                            <p class="desk-sheet-next">{{ $selected->state->nextStep() }}</p>
                            <div class="desk-sheet-act">
                                @if ($sheetAction && $sheetAction['url'])
                                    <a class="btn btn-quiet btn-sm" href="{{ $sheetAction['url'] }}"><span>{{ $sheetAction['label'] }}</span></a>
                                @elseif ($sheetAction)
                                    <span class="desk-sheet-wait">{{ $sheetAction['label'] }}</span>
                                @endif
                                <a href="{{ route('engagements.show', $selected) }}">Open the workspace</a>
                            </div>
                        </section>
                    @else
                        <section class="desk-sheet">
                            <h2 class="desk-sheet-title" id="h-latest">No engagements yet.</h2>
                            <p class="desk-sheet-next">Meetings are booked from inside an engagement. Start one and it shows here with its stage.</p>
                        </section>
                    @endif
                </aside>
            </div>

            <div class="desk-lower">
                <section aria-labelledby="h-meet">
                    <div class="desk-head"><h2 id="h-meet">Meetings<span class="n">{{ $upcoming->count() }}</span></h2><a href="{{ route('meetings.index') }}">All meetings</a></div>
                    @if ($upcoming->isNotEmpty())
                        <ul class="desk-rows">
                            @foreach ($upcoming as $m)
                                <li>
                                    <div><div class="t"><a href="{{ route('meetings.room', $m->room_key) }}">{{ $m->title }}</a></div><div class="s">{{ $m->engagement->title }}</div></div>
                                    @if ($m->status === 'live')<span class="state is-live">Live now</span>@else<time class="e fig" data-local datetime="{{ optional($m->scheduled_for)->toIso8601String() }}">{{ optional($m->scheduled_for)->format('j M H:i') }}</time>@endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="desk-empty">No meetings booked. Start one from inside an engagement so the agreement is one click away.</p>
                    @endif
                </section>
                <section aria-labelledby="h-sign">
                    <div class="desk-head"><h2 id="h-sign">Out for signature<span class="n">{{ $awaitingSignature->count() }}</span></h2></div>
                    @if ($awaitingSignature->isNotEmpty())
                        <ul class="desk-rows">
                            @foreach ($awaitingSignature as $env)
                                <li><div><div class="t"><a href="{{ route('engagements.signing', $env->engagement) }}">{{ $env->engagement->title }}</a></div><div class="s">Sent <time data-local="day" datetime="{{ $env->sent_at?->toIso8601String() }}">{{ $env->sent_at?->format('j M Y') }}</time></div></div></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="desk-empty">Nothing is out for signature.</p>
                    @endif
                </section>
            </div>

            <div class="desk-lower">
                <section aria-labelledby="h-done">
                    <div class="desk-head"><h2 id="h-done">Recently completed<span class="n">{{ $completed->count() }}</span></h2><a href="{{ route('agreements.index') }}">Agreements</a></div>
                    @if ($completed->isNotEmpty())
                        <ul class="desk-rows">
                            @foreach ($completed as $e)
                                <li><div><div class="t"><a href="{{ route('engagements.show', $e) }}">{{ $e->title }}</a></div><div class="s fig">{{ $e->reference }}</div></div><time class="e fig" data-local="day" datetime="{{ $e->completed_at?->toIso8601String() }}">{{ $e->completed_at?->format('j M Y') }}</time></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="desk-empty">No completed agreements yet.</p>
                    @endif
                </section>
                <section aria-labelledby="h-closed">
                    <div class="desk-head"><h2 id="h-closed">Closed without agreement<span class="n">{{ $closed->count() }}</span></h2><a href="{{ route('engagements.index', ['show' => 'closed']) }}">See all closed</a></div>
                    @if ($closed->isNotEmpty())
                        <ul class="desk-rows">
                            @foreach ($closed as $e)
                                <li><div><div class="t"><a href="{{ route('engagements.show', $e) }}">{{ $e->title }}</a></div><div class="s fig">{{ $e->reference }}</div></div><x-state :state="$e->state" /></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="desk-empty">No declined, cancelled or expired engagements.</p>
                    @endif
                </section>
            </div>

            <p class="desk-counts">{{ $activeCount }} active {{ str('engagement')->plural($activeCount) }}, {{ $waiting->count() }} waiting for you, {{ $upcoming->count() }} {{ str('meeting')->plural($upcoming->count()) }}, {{ $outstandingChanges }} proposed {{ str('change')->plural($outstandingChanges) }}. Counted from the records of {{ $team->name }}.</p>
        @endif
    </div>
</x-app-layout>
