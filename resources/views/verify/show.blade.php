<x-plain-layout title="Check a signed agreement">
    <div class="page-head"><div><h1>Check a signed agreement</h1>
        <p class="lede">Upload the PDF you were given. We compare its fingerprint with the signed copies Dot.Engage completed and say whether yours is one of them, unchanged. The file is not stored and nothing about its contents is shown.</p></div></div>

    @if ($result)
        @if ($result['match'])
            <div class="flash is-ok" role="status" style="align-items:center"><x-icon name="shield" />
                <div><b>{{ ['final' => 'This is the signed agreement, unchanged.', 'body' => 'This is the signed pages without the certificate, unchanged.', 'certificate' => 'This is the certificate of completion, unchanged.', 'original' => 'This is the original document that was signed, not the signed copy.'][$result['match']] }}</b></div></div>
        @else
            <div class="flash is-error" role="alert"><x-icon name="warn" /><div><b>No match.</b> This file is not a signed copy Dot.Engage completed, or it has been changed since. Even one changed character gives a different fingerprint.</div></div>
        @endif
        <p class="hashbox" style="margin-bottom:22px">SHA-256 of your file: {{ $result['hash'] }}</p>
    @endif

    @if ($record)
        <dl class="kv" style="margin-bottom:26px">
            <dt>Engagement</dt><dd class="fig">{{ $record['reference'] }}</dd>
            <dt>Completed</dt><dd>{{ $record['completed_at']?->utc()->format('j M Y H:i') }} UTC</dd>
            <dt>Signatures</dt><dd>{{ $record['signers'] }}</dd>
            <dt>Signed file SHA-256</dt><dd class="fig">{{ $record['final_sha256'] }}</dd>
            <dt>Evidence record</dt><dd class="fig">{{ $record['evidence_sha256'] }}</dd>
            <dt>Platform seal</dt><dd><span class="state {{ $record['seal_ok'] ? 'is-ok' : 'is-wax' }}">{{ $record['seal_ok'] ? 'Valid: the evidence record is unchanged since it was sealed' : 'Does not verify' }}</span></dd>
            <dt>Timestamp authority</dt><dd>{{ $record['timestamped'] ? 'Timestamped by '.$record['tsa'] : 'Not used on this deployment' }}</dd>
        </dl>
    @elseif (! $result && $reference)
        <p class="empty"><b>No completed agreement is registered under {{ $reference }}.</b></p>
    @endif

    <form method="post" action="{{ route('verify.check') }}" enctype="multipart/form-data" style="max-width:520px">
        @csrf
        <div class="field"><label for="vf">The PDF to check</label><input class="input" id="vf" type="file" name="file" accept="application/pdf" required>@error('file')<span class="err">{{ $message }}</span>@enderror</div>
        <button class="btn" type="submit"><span>Check it</span></button>
    </form>
    <p class="muted" style="margin-top:28px;max-width:62ch;font-size:var(--t-sm)">The platform seal is Dot.Engage's own signature over the evidence record. It shows the record has not been altered. It is not a qualified electronic signature. Platform seal key: <span class="fig">{{ $key }}</span></p>
</x-plain-layout>
