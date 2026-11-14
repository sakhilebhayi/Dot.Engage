<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dot.Engage · Meet, agree and sign in one place</title>
    <meta name="description" content="Dot.Engage is where businesses meet, discuss a contract, change it, approve it and sign it, with a record anyone can check.">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:title" content="Dot.Engage: meet, agree and sign in one place">
    <meta property="og:description" content="Invite the other party, discuss the contract in the margin, approve changes and sign. No unnecessary emails, no separate video call.">
    <meta property="og:type" content="website">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="/fonts/public-sans-100_900-normal.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/engage-landing.css', 'resources/js/engage.js'])
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <x-icons />
    <header class="topbar"><div class="topbar-in">
        <a class="wordmark" href="{{ url('/') }}" aria-label="Dot.Engage"><x-logo /></a>
        <nav class="nav" aria-label="Main"><a href="{{ route('legal.signatures') }}">How signing works</a><a href="{{ route('verify.show') }}">Check a signed agreement</a></nav>
        <div class="topbar-end">
            @auth<a class="btn btn-sm" href="{{ route('dashboard') }}"><span>Open your desk</span></a>
            @else<a class="btn btn-sm" href="{{ route('login') }}"><span>Sign in</span></a>@endauth
        </div>
    </div></header>

    <main id="main" class="frame lp">
        <section class="lp-hero" aria-labelledby="h1">
            <div>
                <h1 id="h1">Agree it in one place.</h1>
                <p class="sub">Invite the other business, talk the contract through, change it, approve it and sign it. Everything said about a clause stays beside that clause, and the signed result can be checked by anyone holding the file.</p>
                <div class="lp-cta">
                    @auth<a class="btn" href="{{ route('dashboard') }}"><span>Open your desk</span></a>
                    @else<a class="btn" href="{{ route('login') }}"><span>Sign in to start an engagement</span></a>@endauth
                    <a class="btn btn-quiet" href="{{ route('verify.show') }}"><span>Check a signed agreement</span></a>
                </div>
            </div>
            <figure class="fig-margin" aria-label="Example of an agreement with notes in the margin">
                <div class="fig-sheet">
                    <div class="t">SUPPLY AGREEMENT · clause 3</div>
                    <p>3.1 The Supplier shall deliver the Goods to the premises named in each order within the delivery window agreed in that order.</p>
                    <p class="hl-demo">3.2 The Supplier shall give the Buyer the temperature log within <span class="del">twenty-four (24)</span> <span class="ins">twelve (12)</span> hours of delivery.</p>
                    <p>3.3 The Buyer shall ensure the Goods are pre-cooled to 4 degrees Celsius before loading.</p>
                    <span class="fig-label">Example. Names and wording are made up.</span>
                </div>
                <div class="fig-notes" aria-hidden="false">
                    <div class="fig-note pen pen-2"><b><span class="ini ini-sm">T</span> Thandi</b>Can the log arrive within 12 hours? Our buyers need it on arrival.<span class="tag">Proposed change</span></div>
                    <div class="fig-note pen pen-1"><b><span class="ini ini-sm">N</span> Naledi</b>Agreed if the carrier can read the loggers on delivery. Checking with ops.<span class="tag">Open</span></div>
                </div>
            </figure>
        </section>

        <section class="lp-sec" aria-labelledby="h-how">
            <div><h2 id="h-how">From first email to signed.</h2><p class="lead">Every engagement moves through the same six stages. Where it stands is worked out from what has actually happened, never typed in.</p></div>
            <ol class="lp-steps">
                <li><b>Engage</b><span>Name the agreement and the other party. Invite the people who have to take part; they open the link and confirm their email with a one-time code. No account needed.</span></li>
                <li><b>Discuss</b><span>Talk in the engagement or open a meeting with the contract on the side. Nobody joins until the host lets them in.</span></li>
                <li><b>Negotiate</b><span>Comment on the exact clause. Propose a change; someone else reviews it. Accepted changes become a new version, and the earlier ones are kept.</span></li>
                <li><b>Approve</b><span>The people who approve see the current version and say yes or no. A checklist says what is missing before signing can start.</span></li>
                <li><b>Sign</b><span>Each signer sees every page, fills their own fields and signs, in order or all at once. Drawn, typed or uploaded.</span></li>
                <li><b>Complete</b><span>The signed agreement, a certificate and the evidence record are stored together. Anyone can check the file against them.</span></li>
            </ol>
        </section>

        <section class="lp-sec" aria-labelledby="h-proof">
            <div><h2 id="h-proof">A record that holds.</h2><p class="lead">Signing is the easy part. Being able to show what was signed, by whom and when is the point.</p>
                <div class="lp-seal seal-wait" id="seal-demo" style="margin-top:26px"><x-seal reference="ENG-2026-00001" hash="9f2c41a7d0b3e856" date="EXAMPLE" :size="140" /></div></div>
            <dl class="lp-facts">
                <div><dt>The document</dt><dd>Fingerprinted with SHA-256 when it is sent. The signed file begins with those exact bytes, so the original can always be proven.</dd></div>
                <div><dt>Each signer</dt><dd>Their email address confirmed by a one-time code, the time, their network address and device, and the sentence they agreed to.</dd></div>
                <div><dt>The history</dt><dd>Every action is written to a log that links each entry to the one before. Removing or rewriting one breaks the chain, and the database refuses to change it.</dd></div>
                <div><dt>The seal</dt><dd>Dot.Engage signs the evidence record with its own key. That shows the record is unchanged. It is not a qualified electronic signature, and the platform says so on every certificate.</dd></div>
                <div><dt>The meeting</dt><dd>Calls connect browsers directly. Dot.Engage does not record them. Who joined and when is part of the history.</dd></div>
            </dl>
        </section>

        <section class="lp-sec" style="border-bottom:0" aria-labelledby="h-who">
            <div><h2 id="h-who">For businesses and the people they deal with.</h2></div>
            <dl class="lp-facts">
                <div><dt>Your business</dt><dd>Sign in through InfoDot. Your team's roles decide who can invite, change documents, approve and send for signature.</dd></div>
                <div><dt>The other party</dt><dd>Gets an emailed link that shows who invited them and nothing else until they confirm their address. They can read what was shared with them, comment, propose changes, join meetings and sign.</dd></div>
                <div><dt>What it is not</dt><dd>It is not legal advice, and a basic electronic signature is not right for every document or every country. <a href="{{ route('legal.signatures') }}">Read how signing works</a>.</dd></div>
            </dl>
        </section>

        <div class="lp-foot"><span>Dot.Engage</span><a href="{{ route('legal.signatures') }}">How signing works</a><a href="{{ route('verify.show') }}">Check a signed agreement</a><a href="{{ route('terms.show') }}">Terms</a><a href="{{ route('policy.show') }}">Privacy</a><a href="{{ route('cookies') }}">Cookies</a></div>
    </main>
    <script>
        (function () {
            var el = document.getElementById('seal-demo');
            if (!el || !('IntersectionObserver' in window)) { if (el) el.classList.add('is-in'); return; }
            new IntersectionObserver(function (items, o) { items.forEach(function (i) { if (i.isIntersecting) { el.classList.add('is-in'); o.disconnect(); } }); }, { threshold: .6 }).observe(el);
        })();
    </script>
</body>
</html>
