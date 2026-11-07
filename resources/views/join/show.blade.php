<x-plain-layout title="Invitation">
    <div class="page-head">
        <div>
            <h1>{{ $engagement->title }}</h1>
            <p class="lede">
                {{ $invitedBy ?? 'Someone' }} at <b>{{ $host?->name ?? 'a business' }}</b> invited you to take part in this {{ strtolower($engagement->kind->label()) }}.
                @if ($engagement->description) <br><span class="clause" style="display:inline-block;margin-top:8px">{{ $engagement->description }}</span>@endif
            </p>
        </div>
    </div>

    <dl class="kv" style="margin-bottom:28px">
        <dt>Reference</dt><dd class="fig">{{ $engagement->reference }}</dd>
        <dt>You are</dt><dd>{{ $participant->name }} ({{ $participant->role->label() }})</dd>
        <dt>What you can do</dt><dd>Read the agreement, discuss it, propose changes, join meetings@if($participant->hasCapability('sign')), and sign it@endif.</dd>
        <dt>Link valid until</dt><dd><time data-local="day" datetime="{{ $participant->token_expires_at->toIso8601String() }}">{{ $participant->token_expires_at->format('j M Y') }}</time></dd>
    </dl>

    @if ($participant->declined_at)
        <p class="empty"><b>You declined this invitation.</b>If that was a mistake, ask {{ $invitedBy ?? 'the sender' }} to invite you again.</p>
    @else
        <div class="verify">
            <h2>Confirm it is you</h2>
            <p class="muted" style="margin:6px 0 16px">To protect the agreement we email a one-time code to <b class="fig">{{ $masked }}</b>. You do not need an account.</p>

            @if ($errors->has('code'))<div class="flash is-error" role="alert"><x-icon name="warn" /><div>{{ $errors->first('code') }}</div></div>@endif

            <form method="post" action="{{ route('join.code', $token) }}" style="margin-bottom:22px">
                @csrf
                <button class="btn {{ $codeSent ? 'btn-quiet' : '' }}" type="submit"><span>{{ $codeSent ? 'Send a new code' : 'Email me a code' }}</span></button>
            </form>

            @if ($codeSent)
                <form method="post" action="{{ route('join.verify', $token) }}">
                    @csrf
                    <div class="field" style="max-width:260px">
                        <label for="code">Six-digit code</label>
                        <input class="input fig" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="12" required autofocus style="font-size:22px;letter-spacing:.2em">
                    </div>
                    <button class="btn" type="submit"><span>Open the engagement</span></button>
                </form>
            @endif
        </div>

        <details style="margin-top:36px">
            <summary class="linkbtn" style="cursor:pointer">I do not want to take part</summary>
            <form method="post" action="{{ route('join.decline', $token) }}" style="margin-top:14px;max-width:460px">
                @csrf
                <div class="field"><label for="reason">Reason (optional, the owner will see it)</label><textarea class="textarea" id="reason" name="reason" maxlength="500"></textarea></div>
                <button class="btn btn-wax" type="submit"><span>Decline the invitation</span></button>
            </form>
        </details>
    @endif
</x-plain-layout>
