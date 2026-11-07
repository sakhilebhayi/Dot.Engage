<x-workspace :engagement="$engagement" :access="$access" :ws="$ws" tab="meetings">
    @php($canSchedule = $access->can('schedule_meeting'))
    @if ($errors->has('meeting'))<div class="flash is-error" role="alert"><x-icon name="warn" /><div>{{ $errors->first('meeting') }}</div></div>@endif
    <div class="section-head" style="margin-top:0"><h2>Meetings <span class="n">{{ $meetings->count() }}</span></h2>
        @if ($canSchedule)<div class="page-actions">
            <form method="post" action="{{ route('meetings.store', $engagement) }}">@csrf<button class="btn btn-sm" type="submit"><span>Start a meeting now</span></button></form>
            <button type="button" class="btn btn-quiet btn-sm" data-open-dialog="meet-dialog"><span>Schedule one</span></button>
        </div>@endif
    </div>
    <p class="muted" style="margin:10px 0 4px;max-width:64ch">Meetings open on the agreement, so what is agreed is recorded as changes in the engagement. People outside your business join from their invitation and wait to be let in.</p>
    @php($live = $meetings->where('status', 'live'))
    @php($next = $meetings->where('status', 'scheduled'))
    @php($done = $meetings->whereIn('status', ['ended', 'cancelled']))
    @if ($live->isNotEmpty())<h3 style="margin:22px 0 0">Live now</h3><x-meeting-list :meetings="$live" empty="" show="none" />@endif
    <h3 style="margin:22px 0 0">Coming up</h3>
    <x-meeting-list :meetings="$next" empty="Nothing is booked." show="none" />
    @if ($done->isNotEmpty())<h3 style="margin:22px 0 0">Past</h3><x-meeting-list :meetings="$done" empty="" show="none" />@endif

    @if ($canSchedule)
        <dialog class="sheet" id="meet-dialog" aria-labelledby="meet-h">
            <form method="post" action="{{ route('meetings.store', $engagement) }}">
                @csrf
                <input type="hidden" name="tz_offset" id="tz-offset" value="0">
                <div class="sheet-head"><h2 id="meet-h">Schedule a meeting</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                <div class="sheet-body">
                    <div class="field"><label for="m-title">Title</label><input class="input" id="m-title" name="title" maxlength="160" placeholder="Walk through clauses 3 to 6"></div>
                    <div class="row-2">
                        <div class="field"><label for="m-when">Date and time (your time)</label><input class="input" type="datetime-local" id="m-when" name="when" required></div>
                        <div class="field"><label for="m-min">Length (minutes)</label><input class="input" type="number" id="m-min" name="minutes" value="45" min="10" max="480"></div>
                    </div>
                    <p class="hint">Everyone on the engagement is emailed the link and sees the time in their own time zone.</p>
                </div>
                <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Cancel</span></button><button class="btn" type="submit"><span>Schedule</span></button></div>
            </form>
        </dialog>
        @push('scripts')<script>document.getElementById('tz-offset').value = new Date().getTimezoneOffset();</script>@endpush
    @endif
</x-workspace>
