@php
    $signConfig = [
        'urls' => [
            'state' => route('signing.state', [$engagement, $envelope]),
            'submit' => route('signing.submit', $engagement),
            'decline' => route('signing.decline', $engagement),
        ],
        'consent' => $consent, 'name' => $access->name(), 'turn' => $turn, 'internal' => $internal,
    ];
@endphp
<div class="sign-head">
    <div>
        <h1>{{ $contract->title }}</h1>
        <p class="muted">Review the whole agreement, complete your fields, then sign. {{ $engagement->reference }} · version {{ $envelope->version->version_number }}</p>
    </div>
    <dl class="kv sign-facts">
        <dt>You are</dt><dd>{{ $access->name() }}</dd>
        <dt>Identity</dt><dd>{{ $internal ? 'Signed in through InfoDot' : 'Confirmed by a code sent to your email' }}</dd>
        <dt>Document</dt><dd class="fig" title="SHA-256 of what you are signing">{{ substr($envelope->document_sha256, 0, 16) }}…</dd>
        @if ($envelope->expires_at)<dt>Open until</dt><dd><time data-local="day" datetime="{{ $envelope->expires_at->toIso8601String() }}">{{ $envelope->expires_at->format('j M Y') }}</time></dd>@endif
    </dl>
</div>
@unless ($turn)
    <div class="flash"><x-icon name="clock" /><div>Someone has to sign before you. You will be emailed when it is your turn. You can read the agreement meanwhile.</div></div>
@endunless
<div id="sign-done" class="sign-done" hidden>
    <x-seal :reference="$engagement->reference" :hash="$envelope->document_sha256" />
    <div>
        <h2>You signed</h2>
        <p data-done-text class="muted"></p>
        <p style="margin-top:14px"><a class="btn" href="{{ route('engagements.signing', $engagement) }}"><span>See signing progress</span></a></p>
    </div>
</div>
<div id="sign-root" data-config='{{ json_encode($signConfig, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) }}'>
    <p class="empty"><span class="spinner" style="display:inline-block;vertical-align:-2px"></span> Opening the agreement…</p>
</div>
<div class="signbar-wrap" data-sign-bar></div>
@push('scripts')@vite('resources/js/signing/sign.js')@endpush
