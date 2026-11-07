<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $meeting->title }} · Dot.Engage</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    @vite(['resources/css/engage-meeting.css', 'resources/js/engage.js', 'resources/js/meeting/room.js'])
</head>
<body class="room" data-state="lobby">
    <a class="skip" href="#stage">Skip to the call</a>
    <x-icons />
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
        <symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h8M17 7h3M4 17h3M12 17h8"/><circle cx="14.500" cy="7" r="2.200"/><circle cx="9.500" cy="17" r="2.200"/></symbol>
    </defs></svg>

    <header class="room-bar">
        <a class="room-back" href="{{ $access->isInternal() ? route('engagements.meetings', $engagement) : route('engagements.show', $engagement) }}">
            <x-icon name="chev" style="transform:rotate(180deg)" /> <span>{{ $engagement->reference }}</span>
        </a>
        <div class="room-title">
            <h1>{{ $meeting->title }}</h1>
            <span id="room-status" class="state" role="status" aria-live="polite">Not joined</span>
        </div>
        <div class="room-meta">
            <span id="room-clock" class="fig" aria-label="Time in the meeting">00:00</span>
            <span id="room-net" class="net" data-q="" title="Your connection"><x-icon name="signal" size="sm" /><span><b class="net-word">Connection</b><span class="net-rest"></span></span></span>
            <button type="button" class="iconbtn" data-theme-toggle aria-label="Switch light and dark" title="Switch light and dark"><x-icon name="eye" /></button>
        </div>
    </header>

    <div id="room-root" data-leave="{{ $access->isInternal() ? route('engagements.meetings', $engagement) : route('engagements.show', $engagement) }}" data-config='{{ json_encode($config, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) }}'>
        {{-- 1. Lobby: check the camera, microphone and speaker before anyone sees you --}}
        <section id="lobby" class="lobby" aria-labelledby="lobby-h">
            <div class="lobby-preview">
                <video id="lobby-video" autoplay muted playsinline></video>
                <div id="lobby-nocam" class="nocam" hidden><x-icon name="cam-off" size="lg" /><span id="lobby-nocam-text">Camera is off</span></div>
                <div class="lobby-toggles">
                    <button type="button" id="lobby-mic" class="ctl" aria-pressed="true" aria-label="Microphone"><x-icon name="mic" /></button>
                    <button type="button" id="lobby-cam" class="ctl" aria-pressed="true" aria-label="Camera"><x-icon name="cam" /></button>
                </div>
            </div>
            <div class="lobby-form">
                <h2 id="lobby-h">Ready to join?</h2>
                <p class="muted">You will join as <b>{{ $access->name() }}</b>. @if (! $access->can('host_meeting'))The host lets you in from the waiting room. @endif</p>
                <div class="field"><label for="sel-cam">Camera</label><select class="select" id="sel-cam"></select></div>
                <div class="field"><label for="sel-mic">Microphone</label><select class="select" id="sel-mic"></select><div class="meter" aria-hidden="true"><i id="lobby-level"></i></div></div>
                <div class="field" id="sel-spk-wrap"><label for="sel-spk">Speaker</label><select class="select" id="sel-spk"></select></div>
                <p id="lobby-msg" class="lobby-msg" role="status"></p>
                <dl class="kv checks" id="lobby-checks" aria-label="Connection checks"></dl>
                <div class="page-actions"><button type="button" id="btn-join" class="btn"><span>Join now</span></button><a class="btn btn-quiet" href="{{ route('engagements.meetings', $engagement) }}"><span>Not now</span></a></div>
            </div>
        </section>

        {{-- 2. Waiting room --}}
        <section id="waiting" class="waiting" hidden aria-live="polite">
            <h2 id="waiting-h">Waiting for the host</h2>
            <p class="muted" id="waiting-text">The host will let you in shortly. You can leave this page open.</p>
            <button type="button" id="btn-cancel-wait" class="btn btn-quiet"><span>Leave</span></button>
        </section>

        {{-- Why you are not in the call (ended, removed, locked, full, another window) and the way out --}}
        <section id="notice" class="waiting notice" hidden aria-live="assertive">
            <div class="notice-body"></div>
        </section>

        {{-- 3. The call --}}
        <section id="stage" class="stage" hidden aria-label="Call">
            <div id="grid" class="grid" data-count="1"></div>
            <div id="banner" class="banner" role="status" aria-live="polite" hidden></div>

            <aside id="side" class="side" hidden aria-label="Meeting panel">
                <div class="side-head"><h2>Meeting</h2><button type="button" id="side-close" class="ctl ctl-sm" aria-label="Close the panel" title="Close (Esc)"><x-icon name="x" /></button></div>
                <div class="tabs" role="tablist">
                    <button type="button" role="tab" id="tab-people" aria-selected="true" aria-controls="panel-people">People <span class="n" id="people-n">1</span></button>
                    @if ($config['hasDocument'])<button type="button" role="tab" id="tab-agreement" aria-selected="false" aria-controls="panel-agreement">Agreement</button>@endif
                    @if ($config['canSign'])<button type="button" role="tab" id="tab-sign" aria-selected="false" aria-controls="panel-sign">Sign</button>@endif
                </div>
                <div id="panel-people" role="tabpanel" aria-labelledby="tab-people" class="panel"><ul id="people" class="people-list"></ul><div id="waiters" class="waiters"></div></div>
                @if ($config['hasDocument'])<div id="panel-agreement" role="tabpanel" aria-labelledby="tab-agreement" class="panel" hidden><iframe id="frame-agreement" title="The agreement" data-src="{{ $config['urls']['agreement'] }}" loading="lazy"></iframe></div>@endif
                @if ($config['canSign'])<div id="panel-sign" role="tabpanel" aria-labelledby="tab-sign" class="panel" hidden><iframe id="frame-sign" title="Sign the agreement" data-src="{{ $config['urls']['sign'] }}" loading="lazy"></iframe></div>@endif
            </aside>
        </section>

        <div id="controls" class="controls" role="toolbar" aria-label="Call controls" hidden>
            <button type="button" id="ctl-mic" class="ctl" aria-pressed="true" aria-label="Mute microphone"><x-icon name="mic" /></button>
            <button type="button" id="ctl-cam" class="ctl" aria-pressed="true" aria-label="Turn camera off"><x-icon name="cam" /></button>
            <button type="button" id="ctl-share" class="ctl" aria-pressed="false" aria-label="Share your screen"><x-icon name="screen" /></button>
            <details class="ctl-menu" id="ctl-devices"><summary class="ctl" aria-label="Devices" title="Camera, microphone and speaker"><x-icon name="sliders" /></summary><div class="menu-panel" id="devices-panel"></div></details>
            <button type="button" id="ctl-people" class="ctl" aria-pressed="false" aria-label="People and documents"><x-icon name="people" /></button>
            @if ($config['isHost'])
                <button type="button" id="ctl-lock" class="ctl" aria-pressed="false" aria-label="Lock the meeting"><x-icon name="lock" /></button>
            @endif
            <button type="button" id="ctl-leave" class="ctl ctl-leave" aria-label="Leave the meeting"><x-icon name="leave" /></button>
            @if ($config['isHost'])
                <button type="button" id="ctl-end" class="btn btn-wax btn-sm"><span>End for everyone</span></button>
            @endif
        </div>
    </div>

    <noscript><p class="empty" style="padding:24px">Meetings need JavaScript and a recent browser.</p></noscript>
</body>
</html>
