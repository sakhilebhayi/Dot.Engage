<x-workspace :engagement="$engagement" :access="$access" :ws="$ws" tab="activity">
    <div class="section-head" style="margin-top:0">
        <h2>History</h2>
        @if ($canAudit && $chain)
            <span class="state {{ $chain['ok'] ? 'is-ok' : 'is-wax' }}" title="Each entry is chained to the one before it">{{ $chain['ok'] ? 'Chain intact · '.$chain['count'].' entries' : 'Chain broken at entry '.$chain['broken_at'] }}</span>
        @endif
    </div>
    @if (! $canAudit)
        <p class="muted" style="margin-top:12px;max-width:62ch">The full audit record, with network details and the integrity chain, is kept for the owner and reviewers.</p>
    @endif
    @php($visible = $canAudit ? $events : $engagement->auditEvents()->whereNotIn('event', ['participant.code_sent', 'participant.code_failed', 'participant.verified', 'document.viewed', 'signing.viewed', 'engagement.state_changed'])->orderByDesc('id')->limit(200)->get())
    @if ($visible->isNotEmpty())
        <ol class="log">
            @foreach ($visible as $ev)
                <li>
                    <time datetime="{{ \Illuminate\Support\Carbon::parse($ev->occurred_at)->toIso8601String() }}" data-local>{{ \Illuminate\Support\Carbon::parse($ev->occurred_at)->format('j M Y H:i') }}</time>
                    <div>
                        <div>{{ \App\Support\Engage\AuditLabels::describe($ev) }}</div>
                        <div class="actor">{{ $ev->actor_type === 'system' ? 'System' : preg_replace('/\s*<.*>$/', '', $ev->actor_label) }}@if ($canAudit && $ev->ip) · <span class="fig">{{ $ev->ip }}</span>@endif @if ($canAudit) · <span class="fig" title="{{ $ev->hash }}">{{ substr($ev->hash, 0, 10) }}</span>@endif</div>
                    </div>
                </li>
            @endforeach
        </ol>
    @else
        <p class="empty">Nothing has happened yet.</p>
    @endif
</x-workspace>
