@props(['title' => null, 'lede' => null])
<div class="door">
    <div class="door-sheet">
        @if ($title)<h1>{{ $title }}</h1>@endif
        @if ($lede)<p class="lede">{{ $lede }}</p>@endif
        {{ $slot }}
    </div>

    <aside class="door-say" aria-label="About Dot.Engage">
        <p class="door-lead"><span>Meet.</span> <span>Agree.</span> <span>Sign.</span></p>
        <p class="door-sub">Dot.Engage is where two or more businesses talk a contract through, change it, approve it and sign it, in one place.</p>
        <ol class="door-steps">
            <li><b>Meet</b><span>Talk it through in writing or on a call, attached to the agreement itself.</span></li>
            <li><b>Agree</b><span>Propose changes against the clause they concern. Everyone can see what is still open.</span></li>
            <li><b>Sign</b><span>Each person signs with a code sent to their email. The last signature seals the agreement and gives it a link anyone can check.</span></li>
        </ol>
    </aside>
</div>
