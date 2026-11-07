<x-app-layout title="Meetings">
    <div class="page-head">
        <div><h1>Meetings</h1><p class="lede">Every meeting belongs to an engagement, so the agreement is on screen and nothing agreed is lost in the call.</p></div>
    </div>
    @if ($errors->has('meeting'))<div class="flash is-error" role="alert"><x-icon name="warn" /><div>{{ $errors->first('meeting') }}</div></div>@endif

    @if ($engagements->isNotEmpty())
        <form class="docbar" style="margin-bottom:26px" method="post" id="start-form" action="" onsubmit="this.action = this.querySelector('select').value">
            @csrf
            <label for="eng" class="label">Start a meeting about</label>
            <select class="select" id="eng" style="width:auto;max-width:100%;min-width:0">@foreach ($engagements as $e)<option value="{{ route('meetings.store', $e) }}">{{ $e->title }} · {{ $e->reference }}</option>@endforeach</select>
            <button class="btn btn-sm" type="submit"><span>Start now</span></button>
        </form>
    @endif

    @if ($live->isNotEmpty())<div class="section-head"><h2>Live now <span class="n">{{ $live->count() }}</span></h2></div><x-meeting-list :meetings="$live" empty="" />@endif
    <div class="section-head" style="margin-top:30px"><h2>Coming up <span class="n">{{ $upcoming->count() }}</span></h2></div>
    <x-meeting-list :meetings="$upcoming" empty="Nothing is booked. Schedule one from inside an engagement." />
    <div class="section-head" style="margin-top:30px"><h2>Past</h2></div>
    <x-meeting-list :meetings="$past" empty="No meetings have been held yet." />
</x-app-layout>
