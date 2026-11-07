@props(['engagement', 'access', 'ws', 'tab'])
@php
    $stages = $ws->stages();
    $next = $ws->nextAction();
    $hostParty = $engagement->parties->firstWhere('is_host', true);
    $counts = [
        'discussion' => $engagement->messages()->where('is_system', false)->count(),
        'people' => $engagement->participants()->whereNull('revoked_at')->count(),
        'meetings' => $engagement->meetings()->whereIn('status', ['scheduled', 'live'])->count(),
    ];
    $tabs = [
        ['agreement', 'Agreement', route('engagements.show', $engagement), null],
        ['discussion', 'Discussion', route('engagements.discussion', $engagement), $counts['discussion'] ?: null],
        ['people', 'People', route('engagements.people', $engagement), $counts['people']],
        ['meetings', 'Meetings', route('engagements.meetings', $engagement), $counts['meetings'] ?: null],
        ['signing', 'Signing', route('engagements.signing', $engagement), null],
        ['activity', 'History', route('engagements.activity', $engagement), null],
    ];
@endphp
<x-app-layout :title="$engagement->title" wide>
    <header class="ws-head">
        <div class="ws-title">
            <h1>{{ $engagement->title }}</h1>
            <span class="ref">{{ $engagement->reference }}</span>
            <span class="tag">{{ $engagement->kind->label() }}</span>
            @if ($engagement->isOpen() && ($access->can('manage_participants') || $access->can('cancel') || ($access->participant && $access->participant->role->value !== 'owner')))
                <details class="menu" style="margin-left:auto">
                    <summary class="btn btn-quiet btn-sm" style="list-style:none"><span>Engagement</span> <x-icon name="down" size="sm" /></summary>
                    <div class="menu-panel">
                        @if ($access->can('manage_participants'))<button type="button" data-open-dialog="details-dialog">Edit details</button>@endif
                        @if ($access->can('cancel'))<button type="button" data-open-dialog="cancel-dialog">Cancel the engagement</button>@endif
                        @if ($access->isExternal() && $access->participant->role->value !== 'owner')<button type="button" data-open-dialog="decline-dialog">Decline the engagement</button>@endif
                    </div>
                </details>
            @endif
        </div>
        <div class="parties" aria-label="Parties">
            @foreach ($engagement->parties as $party)
                @if (! $loop->first)<span class="sep" aria-hidden="true">with</span>@endif
                <span class="p pen pen-{{ $ws->penFor($party->id) }}"><span class="ini ini-sm">{{ strtoupper(mb_substr($party->name, 0, 1)) }}</span> <span class="by">{{ $party->name }}</span></span>
            @endforeach
        </div>
    </header>

    <div class="next" role="region" aria-label="Where this stands">
        <div class="what">
            <x-state :state="$engagement->state" />
            <span class="step">{{ $engagement->state->nextStep() }}</span>
        </div>
        @if ($next['url'])
            <a class="btn" href="{{ $next['url'] }}"><span>{{ $next['label'] }}</span></a>
        @else
            <span class="muted">{{ $next['label'] }}</span>
        @endif
    </div>

    <div class="ws">
        <aside class="rail" aria-label="Progress">
            <ol>
                @foreach ($stages as $i => $stage)
                    <li class="stage is-{{ $stage['status'] === 'later' ? 'later' : $stage['status'] }}" @if ($stage['status'] === 'now') aria-current="step" @endif>
                        <div class="nm">{{ $stage['name'] }}</div>
                        <div class="fact">{{ $stage['fact'] }}</div>
                    </li>
                @endforeach
            </ol>
            <p class="rail-note">
                @if ($access->isExternal())
                    You joined as {{ $access->participant->name }}. You can only see this engagement.
                @else
                    Opened as {{ $access->name() }}.
                @endif
            </p>
        </aside>

        <div class="ws-main">
            <nav class="tabs" aria-label="Engagement sections">
                @foreach ($tabs as [$key, $label, $href, $n])
                    <a href="{{ $href }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }} @if ($n !== null)<span class="n">{{ $n }}</span>@endif</a>
                @endforeach
            </nav>
            {{ $slot }}
        </div>
    </div>

    @if ($engagement->isOpen())
        @if ($access->can('manage_participants'))
            <dialog class="sheet" id="details-dialog" aria-labelledby="det-h">
                <form method="post" action="{{ route('engagements.update', $engagement) }}">@csrf @method('PATCH')
                    <div class="sheet-head"><h2 id="det-h">Edit details</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                    <div class="sheet-body">
                        <div class="field"><label for="d-title">Title</label><input class="input" id="d-title" name="title" value="{{ $engagement->title }}" required maxlength="160"></div>
                        <div class="field"><label for="d-kind">Kind of agreement</label><select class="select" id="d-kind" name="kind">@foreach (\App\Enums\EngagementKind::options() as $v => $l)<option value="{{ $v }}" @selected($engagement->kind->value === $v)>{{ $l }}</option>@endforeach</select></div>
                        <div class="field"><label for="d-desc">Context for the other party</label><textarea class="textarea" id="d-desc" name="description" maxlength="2000">{{ $engagement->description }}</textarea></div>
                        <div class="field"><label for="d-exp">Closes if not signed by</label><input class="input" type="date" id="d-exp" name="expires_at" value="{{ $engagement->expires_at?->format('Y-m-d') }}"></div>
                    </div>
                    <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Cancel</span></button><button class="btn" type="submit"><span>Save</span></button></div>
                </form>
            </dialog>
        @endif
        @if ($access->can('cancel'))
            <dialog class="sheet" id="cancel-dialog" aria-labelledby="can-h">
                <form method="post" action="{{ route('engagements.cancel', $engagement) }}">@csrf
                    <div class="sheet-head"><h2 id="can-h">Cancel the engagement</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                    <div class="sheet-body"><p class="muted" style="margin-bottom:12px">Everyone is told and any open signing request is cancelled. The history stays. This cannot be undone.</p>
                        <div class="field"><label for="c-reason">Reason</label><textarea class="textarea" id="c-reason" name="reason" required maxlength="500"></textarea></div></div>
                    <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Keep it</span></button><button class="btn btn-wax" type="submit"><span>Cancel the engagement</span></button></div>
                </form>
            </dialog>
        @endif
        @if ($access->isExternal() && $access->participant->role->value !== 'owner')
            <dialog class="sheet" id="decline-dialog" aria-labelledby="dec-h">
                <form method="post" action="{{ route('engagements.decline', $engagement) }}">@csrf
                    <div class="sheet-head"><h2 id="dec-h">Decline the engagement</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                    <div class="sheet-body"><p class="muted" style="margin-bottom:12px">This closes the engagement for everyone and tells the owner. If you only disagree with a clause, leave a note or propose a change instead.</p>
                        <div class="field"><label for="dc-reason">Reason</label><textarea class="textarea" id="dc-reason" name="reason" required maxlength="500"></textarea></div></div>
                    <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Not now</span></button><button class="btn btn-wax" type="submit"><span>Decline</span></button></div>
                </form>
            </dialog>
        @endif
    @endif
</x-app-layout>
