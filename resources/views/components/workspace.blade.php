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
</x-app-layout>
