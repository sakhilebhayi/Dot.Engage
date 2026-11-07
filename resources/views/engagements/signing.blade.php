@php
    $can = fn ($a) => $access->can($a);
    $e = $engagement;
    $status = $envelope?->status;
    $finalising = $envelope && $envelope->status === 'sent' && $envelope->all_signed_at && ! $envelope->finalised_at;
    $prepareConfig = null;
    if (! $envelope || $envelope->isDraft()) {
        if ($contract && $can('prepare_signing')) {
            $prepareConfig = [
                'envelopeId' => $envelope?->id,
                'defaultDays' => config('engage.signing.default_days'),
                'signable' => $signable->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'email' => $p->email, 'pen' => $ws->penFor($p->party_id)])->values(),
                'urls' => array_filter([
                    'draft' => route('signing.draft', $e),
                    'state' => $envelope ? route('signing.state', [$e, $envelope]) : null,
                    'fields' => $envelope ? route('signing.fields', [$e, $envelope]) : null,
                    'send' => $envelope ? route('signing.send', [$e, $envelope]) : null,
                    'cancel' => $envelope ? route('signing.cancel', [$e, $envelope]) : null,
                ]),
            ];
        }
    }
    $okCount = collect($readiness)->where('ok', true)->count();
@endphp
<x-workspace :engagement="$engagement" :access="$access" :ws="$ws" tab="signing">
    @if (! $contract)
        <p class="empty"><b>Nothing to sign yet.</b>Upload the agreement on the Agreement tab first.</p>

    @elseif ($status === 'completed' && $envelope->finalised_at)
        @php($seal = app(\App\Services\Engage\EvidenceSeal::class))
        @php($sealOk = $envelope->evidence && hash_equals((string) $envelope->evidence_sha256, hash('sha256', $envelope->evidence)) && $seal->verify($envelope->evidence_sha256, (string) $envelope->evidence_seal, (string) $envelope->seal_public_key))
        <div class="completed-card">
            <x-seal :reference="$e->reference" :hash="$envelope->final_sha256" :date="strtoupper($envelope->finalised_at->format('j M Y'))" :size="168" />
            <div>
                <h2>Signed and stored</h2>
                <p class="muted" style="margin:6px 0 14px;max-width:56ch">Every required signature is in. The signed agreement, its certificate of completion and the evidence record are below. Nobody can change them: a change would need a new version and a new signing request.</p>
                <div class="page-actions">
                    <a class="btn" href="{{ route('signing.download', [$e, $envelope, 'final']) }}"><span>Download the signed agreement</span></a>
                    <a class="btn btn-quiet" href="{{ route('signing.download', [$e, $envelope, 'certificate']) }}"><span>Certificate</span></a>
                    @if ($can('view_audit') || $can('sign'))<a class="btn btn-quiet" href="{{ route('signing.download', [$e, $envelope, 'evidence']) }}"><span>Evidence record</span></a>@endif
                    <a class="btn btn-quiet" href="{{ route('verify.show', $e->reference) }}"><span>Check it</span></a>
                </div>
            </div>
        </div>
        <dl class="kv">
            <dt>Completed</dt><dd><time data-local datetime="{{ $envelope->finalised_at->toIso8601String() }}">{{ $envelope->finalised_at->format('j M Y H:i') }}</time></dd>
            <dt>Signed file SHA-256</dt><dd class="fig">{{ $envelope->final_sha256 }}</dd>
            <dt>Original SHA-256</dt><dd class="fig">{{ $envelope->document_sha256 }}</dd>
            <dt>Evidence record</dt><dd class="fig">{{ $envelope->evidence_sha256 }}</dd>
            <dt>Platform seal</dt><dd><span class="state {{ $sealOk ? 'is-ok' : 'is-wax' }}">{{ $sealOk ? 'Valid: the record has not changed since it was sealed' : 'Does not match: the stored record has changed' }}</span></dd>
            <dt>Timestamp authority</dt><dd>{{ $envelope->timestamp_token ? 'Timestamped by '.$envelope->timestamp_authority : 'Not used on this deployment. The seal and the time above are Dot.Engage\'s own.' }}</dd>
            <dt>Signatures on the pages</dt><dd>{{ $envelope->stamped ? 'Drawn onto the original, with the certificate added at the end' : 'Not drawn: this PDF could not be stamped. They are recorded in the certificate.' }}</dd>
        </dl>
        <h3 style="margin:26px 0 4px">Who signed</h3>
        <ul class="sstatus">
            @foreach ($envelope->signers->where('status', 'signed') as $s)
                <li class="pen pen-{{ $ws->penFor($s->participant->party_id) }}"><span class="ini">{{ strtoupper(mb_substr($s->participant->name, 0, 1)) }}</span><div><b class="by">{{ $s->participant->name }}</b><div class="row-sub">{{ $s->signed_email }} · {{ $s->method }} signature · {{ $s->identity_method === 'infodot_session' ? 'InfoDot sign-in' : 'email code' }}</div></div><time class="fig muted" data-local datetime="{{ $s->signed_at->toIso8601String() }}">{{ $s->signed_at->format('j M Y H:i') }}</time></li>
            @endforeach
        </ul>

    @elseif ($contract->status === 'signed' && ! $envelope)
        @php($legacy = \App\Models\ContractSignature::where('contract_id', $contract->id)->orderBy('signed_at')->get())
        @php($signedCopy = $contract->versions()->where('kind', 'signed')->latest('version_number')->first())
        <div class="section-head" style="margin-top:0"><h2>Signed before evidence records <span class="n">{{ $legacy->count() }}</span></h2></div>
        <p class="muted" style="margin:10px 0 0;max-width:64ch">This agreement was signed in the earlier Dot.Engage. It has no certificate of completion, platform seal or document fingerprint, so it cannot be checked the way new agreements can. The signatures recorded at the time are listed below.</p>
        <ul class="sstatus">
            @foreach ($legacy as $sig)
                <li><span class="ini ini-sm">{{ strtoupper(mb_substr($sig->signerName(), 0, 1)) }}</span><div><b>{{ $sig->signerName() }}</b><div class="row-sub">{{ $sig->signer_email ?? $sig->user?->email }}@if($sig->ip_address) · <span class="fig">{{ $sig->ip_address }}</span>@endif</div></div><time class="fig muted" data-local datetime="{{ $sig->signed_at->toIso8601String() }}">{{ $sig->signed_at->format('j M Y H:i') }}</time></li>
            @endforeach
        </ul>
        @if ($signedCopy)<p style="margin-top:18px"><a class="btn" href="{{ route('documents.file', [$e, $contract, 'v' => $signedCopy->id, 'download' => 1]) }}"><span>Download the signed copy</span></a></p>@endif

    @elseif ($finalising)
        <div class="empty"><b><span class="spinner" style="display:inline-block;vertical-align:-2px"></span> Every signature is in. Preparing the signed copy.</b>
            The signatures are being drawn onto the agreement and the evidence record is being sealed. This takes a moment and retries by itself if it is interrupted.
            @if ($envelope->finalise_error)<br><span class="muted">Last attempt failed: {{ $envelope->finalise_error }} It will be tried again.</span>@endif
        </div>
        <script>setTimeout(() => location.reload(), 8000)</script>

    @elseif ($status === 'sent')
        @if ($mine && $mine->isOutstanding() && $can('sign'))
            <div class="next" style="margin-top:0"><div class="what"><span class="step"><b>Your signature is needed.</b> Review the agreement and sign.</span></div><a class="btn" href="{{ route('engagements.sign', $e) }}"><span>Review and sign</span></a></div>
        @endif
        <div class="section-head" style="margin-top:0"><h2>Out for signature <span class="n">{{ $envelope->signers->where('status', 'signed')->count() }} of {{ $envelope->signers->where('status', '!=', 'reassigned')->count() }}</span></h2>
            @if ($can('send_signing'))<button type="button" class="btn btn-wax btn-sm" id="cancel-env"><span>Cancel the request</span></button>@endif</div>
        <p class="muted" style="margin:10px 0 0">{{ ucfirst($envelope->mode) }} signing. Version {{ $envelope->version->version_number }} is locked while this is open. Closes <time data-local="day" datetime="{{ $envelope->expires_at?->toIso8601String() }}">{{ $envelope->expires_at?->format('j M Y') }}</time>.</p>
        <ul class="sstatus">
            @foreach ($envelope->signers->where('status', '!=', 'reassigned') as $s)
                <li class="pen pen-{{ $ws->penFor($s->participant->party_id) }}">
                    <span class="ini">{{ strtoupper(mb_substr($s->participant->name, 0, 1)) }}</span>
                    <div><b class="by">{{ $s->participant->name }}</b><div class="row-sub">{{ $s->participant->email }}@if($envelope->mode === 'sequential') · position {{ $s->sign_order }}@endif</div></div>
                    <div class="page-actions">
                        @switch($s->status)
                            @case('signed')<span class="state is-ok">Signed <time class="fig" data-local datetime="{{ $s->signed_at->toIso8601String() }}">{{ $s->signed_at->format('j M H:i') }}</time></span>@break
                            @case('viewed')<span class="state is-warn">Opened, not signed</span>@break
                            @case('notified')<span class="state is-warn">Asked to sign</span>@break
                            @case('declined')<span class="state is-wax">Declined</span>@break
                            @default<span class="state">Waiting for their turn</span>
                        @endswitch
                        @if ($can('send_signing') && $s->isOutstanding())
                            @if (in_array($s->status, ['notified', 'viewed']))<button type="button" class="btn btn-quiet btn-sm" data-remind="{{ route('signing.remind', [$e, $s]) }}"><span>Remind</span></button>@endif
                            <button type="button" class="btn btn-quiet btn-sm" data-reassign="{{ route('signing.reassign', [$e, $s]) }}" data-name="{{ $s->participant->name }}"><span>Reassign</span></button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($can('send_signing'))
            <dialog class="sheet" id="reassign-dialog" aria-labelledby="re-h"><form method="dialog" id="reassign-form">
                <div class="sheet-head"><h2 id="re-h">Reassign the signature</h2><button type="button" class="iconbtn" data-close-dialog aria-label="Close"><x-icon name="x" /></button></div>
                <div class="sheet-body"><p class="muted" id="re-who" style="margin-bottom:12px"></p>
                    <div class="field"><label for="re-to">Give it to</label><select class="select" id="re-to">@foreach ($e->participants()->whereNull('revoked_at')->whereNull('declined_at')->whereNotNull('accepted_at')->get() as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->email }})</option>@endforeach</select></div>
                    <p class="err" id="re-err" role="alert"></p></div>
                <div class="sheet-foot"><button type="button" class="btn btn-quiet" data-close-dialog><span>Cancel</span></button><button class="btn" type="submit"><span>Reassign</span></button></div></form></dialog>
        @endif
        @push('scripts')
        <script type="module">
            const csrf = document.querySelector('meta[name=csrf-token]').content;
            const post = async (url, body = {}) => { const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, credentials: 'same-origin', body: JSON.stringify(body) }); const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.message || 'That did not work.'); return j; };
            document.querySelectorAll('[data-remind]').forEach((b) => b.addEventListener('click', async () => { b.setAttribute('data-busy', 'true'); try { const j = await post(b.dataset.remind); b.querySelector('span').textContent = 'Reminded'; } catch (e) { alert(e.message); } finally { b.removeAttribute('data-busy'); } }));
            let reUrl = null;
            document.querySelectorAll('[data-reassign]').forEach((b) => b.addEventListener('click', () => { reUrl = b.dataset.reassign; document.getElementById('re-who').textContent = 'Move the signature of ' + b.dataset.name + ' to someone else. They are emailed and get the same fields.'; document.getElementById('reassign-dialog').showModal(); }));
            document.getElementById('reassign-form')?.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await post(reUrl, { participant_id: parseInt(document.getElementById('re-to').value, 10) }); location.reload(); } catch (e) { document.getElementById('re-err').textContent = e.message; } });
            document.getElementById('cancel-env')?.addEventListener('click', async () => { const reason = prompt('Why are you cancelling this signing request? The signers will be told.'); if (!reason) return; try { await post(@json(route('signing.cancel', [$e, $envelope])), { reason }); location.reload(); } catch (e) { alert(e.message); } });
        </script>
        @endpush

    @else
        {{-- Nothing sent: prepare it --}}
        <div class="section-head" style="margin-top:0"><h2>Prepare the signing request <span class="n">{{ $okCount }} of {{ count($readiness) }} ready</span></h2></div>
        <ul class="checks" aria-label="Before signing">
            @foreach ($readiness as $item)
                <li class="{{ $item['ok'] ? 'is-ok' : 'is-no' }}"><span class="box" aria-hidden="true">@if($item['ok'])<x-icon name="check" size="sm" style="width:12px;height:12px;stroke-width:3" />@endif</span><div>{{ $item['label'] }}<div class="detail">{{ $item['detail'] }}</div></div><span class="state {{ $item['ok'] ? 'is-ok' : 'is-warn' }}">{{ $item['ok'] ? 'Ready' : 'To do' }}</span></li>
            @endforeach
        </ul>
        <div style="margin-top:30px">
            @if ($prepareConfig)
                <div id="prepare-root" data-config='{{ json_encode($prepareConfig, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) }}'><p class="empty"><span class="spinner" style="display:inline-block;vertical-align:-2px"></span> Loading…</p></div>
                @push('scripts')@vite('resources/js/signing/prepare.js')@endpush
            @else
                <p class="empty"><b>Waiting for the signing request.</b>The owner or a contract manager prepares it once the terms are agreed. You will be asked to sign when it is sent.</p>
            @endif
        </div>
    @endif

    @if ($past->isNotEmpty())
        <h3 style="margin:36px 0 4px">Earlier requests</h3>
        <ul class="rows">@foreach ($past as $old)<li style="--cols: 1fr auto"><div><span class="row-title">Version {{ $old->version->version_number }}</span><div class="row-sub">{{ $old->cancel_reason ?: ucfirst($old->status) }}</div></div><span class="state is-wax">{{ ucfirst($old->status) }}</span></li>@endforeach</ul>
    @endif
</x-workspace>
