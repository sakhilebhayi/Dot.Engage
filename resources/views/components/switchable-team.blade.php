@props(['team', 'component' => null])
@php($current = Auth::user()->isCurrentTeam($team))

<form method="POST" action="{{ route('current-team.update') }}" class="swt">
    @method('PUT')
    @csrf

    <input type="hidden" name="team_id" value="{{ $team->id }}">

    <button type="submit" class="swt-btn" @if ($current) aria-current="true" @endif>
        <span class="swt-mark" aria-hidden="true">@if ($current)<x-icon name="check" />@endif</span>
        <span class="swt-name">{{ $team->name }}</span>
        @if ($current)<span class="sr-only">(current business)</span>@endif
    </button>
</form>
