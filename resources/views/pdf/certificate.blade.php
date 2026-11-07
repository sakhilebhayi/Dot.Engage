<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Certificate of completion {{ $engagement->reference }}</title>
<style>
    @page { margin: 54px 54px 64px; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5px; color: #15181b; line-height: 1.5; }
    h1 { font-size: 19px; margin: 0 0 3px; }
    h2 { font-size: 11px; margin: 20px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #15181b; }
    .sub { color: #596067; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { text-align: left; vertical-align: top; padding: 4px 6px 4px 0; border-bottom: 1px solid #d3d6d9; }
    th { font-size: 8.5px; color: #596067; font-weight: normal; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; word-break: break-all; }
    .box { border: 1px solid #15181b; padding: 8px 10px; margin-top: 12px; }
    .muted { color: #596067; }
    .sig { height: 34px; }
</style></head><body>
<h1>Certificate of completion</h1>
<div class="sub">{{ $engagement->reference }} &middot; {{ $contract->title }} &middot; version {{ $version->version_number }}</div>

<table>
    <tr><th style="width:150px">Engagement</th><td>{{ $engagement->title }} ({{ $engagement->reference }})</td></tr>
    <tr><th>Parties</th><td>{{ $engagement->parties->pluck('name')->join(' with ') }}</td></tr>
    <tr><th>Document SHA-256</th><td class="mono">{{ $envelope->document_sha256 }}</td></tr>
    <tr><th>Signing mode</th><td>{{ ucfirst($envelope->mode) }}; sent {{ $envelope->sent_at?->utc()->format('j M Y H:i') }} UTC; last signature {{ $envelope->all_signed_at?->utc()->format('j M Y H:i') }} UTC</td></tr>
    <tr><th>Signature level</th><td>Basic electronic signature. Each signer's identity is the email address they confirmed with a one-time code (or their InfoDot session). This is not an advanced or qualified electronic signature.</td></tr>
    <tr><th>Signatures on the pages</th><td>{{ $stamped ? 'Drawn onto the pages of the original; the original file content is unchanged underneath.' : 'This file could not be stamped. The signatures are recorded here and in the evidence record; the original file is unchanged.' }}</td></tr>
</table>

<h2>Signatories</h2>
<table>
    <tr><th>Name and email</th><th>Identity checked by</th><th>Signed (UTC)</th><th>Network address and device</th></tr>
    @foreach ($signers as $s)
        <tr>
            <td><b>{{ $s->participant->name }}</b><br>{{ $s->signed_email }}<br><span class="muted">{{ $s->method }} signature</span></td>
            <td>{{ $s->identity_method === 'infodot_session' ? 'Signed-in InfoDot session' : 'One-time code sent to the address' }}<br><span class="muted">{{ $s->identity_verified_at?->utc()->format('j M Y H:i') }}</span></td>
            <td>{{ $s->signed_at?->utc()->format('j M Y H:i:s') }}</td>
            <td class="mono">{{ $s->ip }}<br>{{ \Illuminate\Support\Str::limit($s->user_agent, 70) }}</td>
        </tr>
    @endforeach
</table>

<h2>What each signer agreed to</h2>
<p>{{ $consent }}</p>
<p class="muted">Consent text SHA-256: <span class="mono">{{ hash('sha256', $consent) }}</span></p>

<h2>Events</h2>
<table>
    @foreach ($events as $ev)
        <tr><td style="width:120px" class="mono">{{ \Illuminate\Support\Carbon::parse($ev->occurred_at)->utc()->format('Y-m-d H:i:s') }}</td><td>{{ \App\Support\Engage\AuditLabels::describe($ev) }}</td><td class="muted">{{ preg_replace('/\s*<.*>$/', '', $ev->actor_label) }}</td></tr>
    @endforeach
</table>

<div class="box">
    <b>Checking this agreement.</b> The SHA-256 of the complete signed file, the evidence record and the platform's seal over it are stored with the engagement and are not printed here (a file cannot contain its own hash).
    Anyone holding this PDF can check it at <b>{{ $verifyUrl }}</b> by uploading it; the page says whether it is the signed copy of {{ $engagement->reference }}, unchanged since completion.
    <br><br><span class="muted">Generated {{ $generatedAt->utc()->format('j M Y H:i') }} UTC by Dot.Engage. The platform seal is Dot.Engage's own attestation that the record has not changed. It does not replace a certificate from an accredited trust service provider where the law requires one.</span>
</div>
</body></html>
