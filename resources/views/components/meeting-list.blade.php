@props(['meetings', 'empty', 'show' => 'engagement'])
@if ($meetings->isNotEmpty())
    <ul class="rows">
        @foreach ($meetings as $m)
            <li style="--cols: 1fr auto auto">
                <div>
                    <a class="row-title" href="{{ route('meetings.room', $m->room_key) }}">{{ $m->title }}</a>
                    <div class="row-sub">@if ($show === 'engagement'){{ $m->engagement->title }} · @endif{{ $m->kind === 'instant' ? 'Started on the spot' : 'Scheduled' }} · {{ $m->duration_minutes }} min</div>
                </div>
                <div>
                    @if ($m->status === 'live')<span class="state is-live">Live now</span>
                    @elseif ($m->status === 'scheduled')<span class="state">Scheduled</span>
                    @elseif ($m->status === 'cancelled')<span class="state is-wax">Cancelled</span>
                    @else<span class="state">Ended</span>@endif
                    <div class="row-sub fig"><time data-local datetime="{{ ($m->started_at ?? $m->scheduled_for)?->toIso8601String() }}">{{ ($m->started_at ?? $m->scheduled_for)?->format('j M H:i') }}</time></div>
                </div>
                <div class="page-actions">
                    @if (in_array($m->status, ['live', 'scheduled']))<a class="btn btn-sm" href="{{ route('meetings.room', $m->room_key) }}"><span>{{ $m->status === 'live' ? 'Join' : 'Open' }}</span></a>@endif
                </div>
            </li>
        @endforeach
    </ul>
@else
    <p class="empty">{{ $empty }}</p>
@endif
