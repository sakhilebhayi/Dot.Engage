@props(['title' => null, 'header' => null, 'wide' => false])
@php
    $user = auth()->user();
    $unread = $user ? $user->unreadNotifications()->count() : 0;
    // Five areas, all visible: the bar holds them and "More" takes over by itself when it cannot. Order is priority.
    $nav = [
        ['label' => 'Desk', 'href' => route('dashboard'), 'current' => request()->routeIs('dashboard')],
        ['label' => 'Engagements', 'href' => route('engagements.index'), 'current' => request()->routeIs('engagements.*')],
        ['label' => 'Meetings', 'href' => route('meetings.index'), 'current' => request()->routeIs('meetings.*')],
        ['label' => 'Agreements', 'href' => route('agreements.index'), 'current' => request()->routeIs('agreements.*')],
        ['label' => 'Team chat', 'href' => route('chat.index'), 'current' => request()->routeIs('chat.*')],
    ];
    // A person with no account (an invited party on a sealed link) sees the two public explainers and nothing else.
    $outsideNav = [
        ['label' => 'How signing works', 'href' => route('legal.signatures')],
        ['label' => 'Check a signed agreement', 'href' => route('verify.show')],
    ];
    $account = null;
    if ($user) {
        $links = [
            ['label' => 'Profile and security', 'href' => route('profile.show')],
        ];
        if ($user->currentTeam) {
            $links[] = ['label' => 'Business and members', 'href' => route('teams.show', $user->currentTeam->id)];
        }
        $links[] = ['label' => 'Templates', 'href' => route('contracts.templates')];
        $links[] = ['label' => 'How signing works', 'href' => route('legal.signatures')];
        if ($user->is_platform_operator) {
            $links[] = ['label' => 'Operator', 'href' => route('operator.dependency-patches.index')];
        }
        $businesses = $user->allTeams();
        if ($businesses->count() > 1) {
            $links[] = ['rule' => true];
            $links[] = ['heading' => 'Switch business'];
            foreach ($businesses as $business) {
                $links[] = ['label' => $business->name, 'current' => $user->isCurrentTeam($business), 'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $business->id]]];
            }
        }
        $account = [
            'name' => $user->name,
            'team' => $user->currentTeam?->name ?? 'No business yet',
            'initials' => strtoupper(mb_substr($user->name, 0, 1)),
            'links' => $links,
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-key="engage-theme">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? (is_string($header) ? $header : 'Dot.Engage') }} · Dot.Engage</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- Roboto, the type scale and the shared header, search and switcher (dot-ui), all served from this site's own public/dot-ui. Loaded before the app CSS so the host mapping blocks in shell.css win. --}}
    <x-dot-head ui />
    {{-- One script: the header, search, switcher and account menu. The results controller inside the search is lazy (it loads on the first focus in the search); this host only prints results-nav markup from Livewire and never calls DotSearchResults.attach(), so the core bundle is enough. --}}
    <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" defer></script>
    @vite(['resources/css/engage.css', 'resources/css/signed-in.css', 'resources/js/app.js', 'resources/js/engage.js'])
    @livewireStyles
    @stack('head')
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>
    <x-icons />

    {{-- The shared top bar (dot-ui header): one grid, the search truly centred, items in a priority list, the phone menu a drawer. Outside parties
         (invited by a sealed link, no account) get the logo only: no search, no switcher, no account, nothing that is not theirs. --}}
    <x-dot.header :brand-href="$user ? route('dashboard') : url('/')" :brand-label="$user ? 'Dot.Engage, desk' : 'Dot.Engage'" :nav="$user ? $nav : $outsideNav" :account="$account" skip="#main" class="dh--swpanel">
        <x-slot:brand><x-logo :size="34" class="dh-logo" /></x-slot:brand>
        @if ($user)
            <x-slot:search><livewire:header-search /></x-slot:search>
            <x-slot:tools>
                <x-dot.switcher platform="engage" :company="$user->currentTeam?->infodot_team_uuid" :company-name="$user->currentTeam?->name" logos="/dot-ui/img/platforms" />
                <x-dot.bell :href="route('notifications.index')" :count="$unread" :always="true" />
            </x-slot:tools>
        @endif
    </x-dot.header>

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
