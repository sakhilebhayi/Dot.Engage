<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Engage — Share the contract. Talk it through. Sign it live.</title>
        <meta name="description" content="Upload contracts, share them with clients, negotiate terms over real-time chat or a live video call, and capture legally-relevant e-signatures — from a signature pad or directly inside the call.">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Karla:wght@400;500;600;700&family=Martian+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
        <script defer src="https://unpkg.com/alpinejs@3.10.2/dist/cdn.min.js"></script>

        <style>
            :root {
                --paper: #f5f5f2;
                --panel: #fdfdfb;
                --ink: #171b3d;
                --ink-soft: #565a78;
                --gold: #c99a1a;
                --gold-bright: #e8bb2c;
                --navy: #171b3d;
                --navy-soft: #454a78;
                --line: rgba(23, 27, 61, 0.12);
                --font-display: 'Sora', ui-sans-serif, sans-serif;
                --font-body: 'Karla', system-ui, sans-serif;
                --font-mono: 'Martian Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); margin: 0; padding: 0; }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
                .draw-path {
                    stroke-dasharray: 400;
                    stroke-dashoffset: 400;
                    transition: stroke-dashoffset 1300ms var(--ease-out) 300ms;
                }
                .draw-path.is-visible { stroke-dashoffset: 0; }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
                .draw-path { stroke-dashoffset: 0; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(23, 27, 61, 0.025); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#f5f5f2]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    {{-- Header overlays a hero photo with a ~92%-opacity dark
                         --ink scrim at this exact position (unlike the
                         footer below, which sits on the page's light paper
                         and keeps the default logo). --}}
                    <img src="{{ asset('images/logo-light.png') }}" alt="Dot.Engage" class="h-14 sm:h-[4.5rem] w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#contracts" class="link-underline hover:text-[var(--ink)] pb-0.5">Contracts</a>
                    <a href="#conversations" class="link-underline hover:text-[var(--ink)] pb-0.5">Conversations</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--navy-soft)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--navy-soft)] text-white text-sm font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#contracts" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Contracts</a>
                    <a href="#conversations" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Conversations</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative pt-32 pb-16 sm:pb-24 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: a man signing a document with a pen, by Jakub Żerdzicki (@jakubzerdzicki), unsplash.com/photos/man-signing-a-document-with-a-pen-QI6NLgN5XnM -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1752650733337-cb0189176fb9?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(100deg, rgba(23,27,61,0.92) 0%, rgba(23,27,61,0.80) 32%, rgba(23,27,61,0.45) 55%, rgba(23,27,61,0.18) 75%, rgba(23,27,61,0.02) 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(23,27,61,0) 0%, rgba(23,27,61,0.10) 55%, rgba(23,27,61,0.35) 80%, #f5f5f2 100%);"></div>
            <div class="relative z-10 max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                            Contracts, chat &amp; e-signatures
                        </p>
                        <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--paper)] mb-6">
                            Share it.<br>Talk it through.<br>Sign it live.
                        </h1>
                        <p class="text-lg text-[var(--paper)] leading-relaxed max-w-xl mb-10">
                            Dot.Engage is where teams upload contracts, share them with clients, negotiate terms over real-time chat or a live video call, and capture legally-relevant e-signatures — from a signature pad or directly inside the call.
                        </p>

                        @guest
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--navy-soft)] text-white font-display font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                                <a href="#conversations" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-full border border-[rgba(245,245,242,0.35)] hover:border-[var(--navy-soft)] transition-colors">
                                    See how signing works
                                </a>
                            </div>
                        @endguest
                    </div>

                    <!-- Signature element: the speech-bubble icon from the real logo mark, carrying a signature scrawl — chat and e-signature in one shape -->
                    <div class="reveal" data-reveal>
                        <div class="relative rounded-[2rem] border border-[var(--line)] bg-[var(--panel)] p-6 sm:p-8 shadow-[0_30px_60px_-30px_rgba(23,27,61,0.25)]">
                            <div class="flex items-center justify-between mb-6 font-mono text-[11px] tracking-[0.12em] uppercase text-[var(--ink-soft)]">
                                <span>Contract #118</span>
                                <span>Pending</span>
                            </div>

                            <svg viewBox="0 0 400 260" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto" aria-hidden="true">
                                <rect x="0.5" y="0.5" width="399" height="259" rx="14" stroke="var(--line)" stroke-dasharray="4 4"/>
                                <!-- speech bubble, echoing the logo's chat icon -->
                                <path d="M60 60 Q60 40 80 40 L320 40 Q340 40 340 60 L340 150 Q340 170 320 170 L140 170 L100 205 L108 170 L80 170 Q60 170 60 150 Z"
                                    stroke="var(--navy)" stroke-width="2.5" fill="var(--panel)"/>
                                <!-- signature scrawl inside the bubble -->
                                <path
                                    class="draw-path"
                                    data-reveal
                                    d="M95 115 C 105 90, 120 90, 125 110 C 130 130, 140 95, 150 100 C 160 105, 155 130, 170 120 C 185 110, 190 95, 205 100 C 220 105, 210 130, 230 120 C 250 110, 260 95, 285 100"
                                    stroke="var(--gold)"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    fill="none"
                                />
                            </svg>

                            <div class="flex items-center justify-between mt-6 pt-6 border-t border-[var(--line)]">
                                <span class="font-mono text-[11px] tracking-wide text-[var(--ink-soft)]">Signed during video call</span>
                                <span class="font-mono text-[11px] tracking-wide text-[var(--gold)] font-medium">2 of 3 signed</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contracts / Conversations -->
        <section class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-2xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">What it's built from</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        The deal and the conversation, in the same place
                    </h2>
                </div>

                <div class="grid lg:grid-cols-2 gap-x-16">
                    <div id="contracts">
                        <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-1">Contracts</h3>
                        <p class="text-sm text-[var(--ink-soft)] mb-6">Upload, version, and sign.</p>
                        <div class="border-t border-[var(--line)]">
                            @php
                                $contractItems = [
                                    ['tag' => 'Contract', 'title' => 'Draft, pending, or signed', 'body' => 'An uploaded document with a title, description, and expiry, held on a private file path with soft-delete history.'],
                                    ['tag' => 'Signature', 'title' => 'Captured signatures', 'body' => 'Each team member\'s signature on a contract records the image, IP address, and a signed timestamp.'],
                                    ['tag' => 'Version', 'title' => 'Version history', 'body' => 'Every re-uploaded contract file keeps a version entry, so nobody signs the wrong draft.'],
                                ];
                            @endphp
                            @foreach ($contractItems as $item)
                                <div class="row-hover border-b border-[var(--line)] px-1 py-6 transition-colors reveal" data-reveal>
                                    <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--navy)] mb-2">{{ $item['tag'] }}</p>
                                    <h4 class="font-display font-semibold text-lg text-[var(--ink)] mb-1.5">{{ $item['title'] }}</h4>
                                    <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $item['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div id="conversations" class="mt-14 lg:mt-0">
                        <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-1">Conversations &amp; calls</h3>
                        <p class="text-sm text-[var(--ink-soft)] mb-6">Negotiate, then close, without leaving the thread.</p>
                        <div class="border-t border-[var(--line)]">
                            @php
                                $conversationItems = [
                                    ['tag' => 'Chat', 'title' => 'Real-time conversations', 'body' => '1:1 or group threads, team-scoped, with messages that can carry a file attachment or link straight to a contract.'],
                                    ['tag' => 'Video', 'title' => 'Live video sessions', 'body' => 'A call moves through waiting, active, and ended — and can be tied to a contract for signing while everyone is still on the line.'],
                                    ['tag' => 'In-call signing', 'title' => 'Sign without hanging up', 'body' => 'A signature captured live during a call is recorded the same way as one from the standalone signature pad.'],
                                ];
                            @endphp
                            @foreach ($conversationItems as $item)
                                <div class="row-hover border-b border-[var(--line)] px-1 py-6 transition-colors reveal" data-reveal>
                                    <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold)] mb-2">{{ $item['tag'] }}</p>
                                    <h4 class="font-display font-semibold text-lg text-[var(--ink)] mb-1.5">{{ $item['title'] }}</h4>
                                    <p class="text-[var(--ink-soft)] leading-relaxed text-sm">{{ $item['body'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Ecosystem -->
        <section class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-bright)] mb-4">Part of the Dot Ecosystem</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-white leading-tight mb-5">
                            Built on real-time infrastructure, not a polling loop
                        </h2>
                        <p class="text-[#c4c6dc] leading-relaxed max-w-sm">
                            Chat and video signalling run on Laravel Reverb with real broadcast events wired end to end — further along than most platforms in the ecosystem, where Reverb is often configured but unused.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Team-scoped by design', 'body' => 'Contracts, conversations, and video sessions are all scoped to your current team at the query level.'],
                                ['title' => 'Graceful video fallback', 'body' => 'Daily.co powers video when configured; without it, the platform falls back to a Reverb-only signalling path.'],
                                ['title' => 'Signed PDF delivery', 'body' => 'A completed contract is rendered to PDF once every required signature is captured.'],
                                ['title' => 'Ecosystem single sign-on', 'body' => 'A one-time Sanctum token minted elsewhere in the ecosystem logs you straight into your dashboard.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[rgba(255,255,255,0.14)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-white mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[#c4c6dc] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: a woman waving during a video call on her laptop, by Vitaly Gariev (@silverkblack), unsplash.com/photos/woman-waving-during-a-video-call-on-her-laptop-nSj0hdQUrW0 -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1763729805496-b5dbf7f00c79?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #f5f5f2 0%, rgba(23,27,61,0.80) 20%, rgba(23,27,61,0.85) 50%, rgba(23,27,61,0.80) 80%, #f5f5f2 100%);"></div>
            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Get the next contract signed this week
                </h2>
                <p class="text-[var(--paper)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Sign in with your Dot Ecosystem account or create one to upload your first contract.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--ink)] hover:bg-[var(--navy-soft)] text-white font-display font-semibold rounded-full transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-full border border-[rgba(245,245,242,0.35)] hover:border-[var(--navy-soft)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Engage" class="h-11 w-auto opacity-90">
                </a>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="{{ route('policy.show') }}" class="hover:text-[var(--ink)] transition-colors">Privacy</a>
                    <a href="{{ route('cookies') }}" class="hover:text-[var(--ink)] transition-colors">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="hover:text-[var(--ink)] transition-colors">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Engage. Contracts, chat, and e-signatures for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
