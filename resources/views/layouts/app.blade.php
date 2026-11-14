@props(['title' => null, 'header' => null, 'wide' => false])
@php
    $user = auth()->user();
    $unread = $user ? $user->unreadNotifications()->count() : 0;
    $nav = [
        ['desk', 'Desk', route('dashboard'), request()->routeIs('dashboard')],
        ['engagements', 'Engagements', route('engagements.index'), request()->routeIs('engagements.*')],
        ['meetings', 'Meetings', route('meetings.index'), request()->routeIs('meetings.*')],
        ['agreements', 'Agreements', route('agreements.index'), request()->routeIs('agreements.*')],
        ['chat', 'Team chat', route('chat.index'), request()->routeIs('chat.*')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? (is_string($header) ? $header : 'Dot.Engage') }} · Dot.Engage</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preload" href="/fonts/public-sans-100_900-normal.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/engage.css', 'resources/css/signed-in.css', 'resources/js/app.js', 'resources/js/engage.js'])
    @livewireStyles
    @stack('head')
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <x-icons />

    <header class="topbar">
        <div class="topbar-in">
            <a class="wordmark" href="{{ route('dashboard') }}" aria-label="Dot.Engage, desk">
                <x-logo />
            </a>
            <nav class="nav" aria-label="Main">
                @foreach ($nav as [$key, $label, $href, $active])
                    <a href="{{ $href }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="topbar-end">
                @auth
                    <form class="search" action="{{ route('engagements.index') }}" method="get" role="search" data-no-busy>
                        <x-icon name="search" size="sm" />
                        <input type="search" name="q" value="{{ request('q') }}" placeholder="Find an engagement" aria-label="Find an engagement">
                    </form>
                    <a class="iconbtn" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}">
                        <x-icon name="bell" />
                        @if ($unread)<span class="count">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
                    </a>
                    <details class="menu">
                        <summary class="iconbtn" aria-label="Account menu"><span class="ini ini-sm pen-4">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span></summary>
                        <div class="menu-panel">
                            <div class="who"><b>{{ $user->name }}</b><span>{{ $user->currentTeam?->name ?? 'No business yet' }}</span></div>
                            <a href="{{ route('profile.show') }}">Profile and security</a>
                            @if ($user->currentTeam)<a href="{{ route('teams.show', $user->currentTeam->id) }}">Business and members</a>@endif
                            <a href="{{ route('contracts.templates') }}">Templates</a>
                            <a href="{{ route('legal.signatures') }}">How signing works</a>
                            @if ($user->is_platform_operator)<a href="{{ route('operator.dependency-patches.index') }}">Operator</a>@endif
                            @php($businesses = $user->allTeams())
                            @if ($businesses->count() > 1)
                                <div class="menu-sep" role="separator"></div>
                                <div class="menu-label" id="switch-business">Switch business</div>
                                <div role="group" aria-labelledby="switch-business">
                                    @foreach ($businesses as $business)<x-switchable-team :team="$business" />@endforeach
                                </div>
                                <div class="menu-sep" role="separator"></div>
                            @endif
                            <button type="button" data-theme-toggle>Switch light and dark</button>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
                        </div>
                    </details>
                @endauth
                <details class="menu mobile-nav">
                    <summary class="iconbtn" aria-label="Menu"><x-icon name="menu" /></summary>
                    <div class="menu-panel">
                        @foreach ($nav as [$key, $label, $href, $active])
                            <a href="{{ $href }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </div>
                </details>
            </div>
        </div>
    </header>

    <main id="main" class="frame {{ $wide ? 'frame-wide' : '' }}">
        @if (session('status') || session('flash'))
            <div class="flash is-ok" role="status">{{ session('status') ?? session('flash') }}</div>
        @endif
        @if ($errors->any() && ! isset($suppressErrors))
            <div class="flash is-error" role="alert"><x-icon name="warn" /><div>{{ $errors->first() }}</div></div>
        @endif
        {{ $slot }}
        <div class="foot">
            <span>Dot.Engage</span>
            <a href="{{ route('legal.signatures') }}">How signing works</a>
            <a href="{{ route('terms.show') }}">Terms</a>
            <a href="{{ route('policy.show') }}">Privacy</a>
            <a href="{{ route('cookies') }}">Cookies</a>
        </div>
    </main>

    @stack('modals')
    @livewireScripts
    @stack('scripts')
</body>
</html>
