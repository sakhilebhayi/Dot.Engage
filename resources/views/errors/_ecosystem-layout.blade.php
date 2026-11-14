<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} · @yield('title') · Dot.Engage</title>
    <meta name="robots" content="noindex">

    @php
        $faviconPath = null;
        foreach (['favicon.ico', 'favicon-32x32.png', 'favicon-16x16.png'] as $faviconCandidate) {
            if (file_exists(public_path($faviconCandidate))) {
                $faviconPath = $faviconCandidate;
                break;
            }
        }

        // The built stylesheet is optional here: an error page must still read if the build is missing.
        $viteManifestPath = public_path('build/manifest.json');
        $viteEntries = [];
        if (file_exists($viteManifestPath)) {
            $viteManifest = json_decode(file_get_contents($viteManifestPath), true) ?? [];
            foreach (['resources/css/engage.css', 'resources/js/engage.js'] as $entry) {
                if (isset($viteManifest[$entry])) {
                    $viteEntries[] = $entry;
                }
            }
        }

        // Every Dot platform, pulled from the shared ecosystem registry (config/ecosystem.php, identical across
        // all platforms) rather than a fixed subset: add a platform to the registry once and it shows up here.
        // Self-exclusion uses this literal name rather than config('app.name'), since not every platform's
        // .env reliably has APP_NAME set correctly.
        // A page that sets `quiet` (the company does not hold the platform) offers one way forward only: no list of
        // other platforms, and no button back to a desk that would only show the same page again.
        $quiet = (bool) ($quiet ?? false);
        $currentPlatformName = 'Dot.Engage';
        $discover = $quiet ? [] : collect(config('ecosystem.platforms', []))
            ->reject(fn ($p) => ($p['name'] ?? null) === $currentPlatformName)
            ->reject(fn ($p) => ($p['active'] ?? true) === false)
            ->reject(fn ($p, $k) => ($p['internal'] ?? false) === true || in_array($k, ['memory', 'brain', 'dopemine'], true))
            ->values()
            ->all();
    @endphp
    @if ($faviconPath)
        <link rel="icon" href="{{ asset($faviconPath) }}">
    @endif
    @if (! empty($viteEntries))
        <link rel="preload" href="/fonts/public-sans-100_900-normal.woff2" as="font" type="font/woff2" crossorigin>
        @vite($viteEntries)
    @else
        <style>
            body { margin: 0; font: 16px/1.5 system-ui, sans-serif; background: #eceeec; color: #15181b; }
            main { max-width: 640px; margin: 0 auto; padding: 48px 20px; }
            @media (prefers-color-scheme: dark) { body { background: #111416; color: #eceeed; } }
            .brand-logo { display: inline-flex; } .brand-logo img { display: block; height: 46px; width: auto; }
            .logo-on-dark { display: none; }
            @media (prefers-color-scheme: dark) { .logo-on-light { display: none; } .logo-on-dark { display: block; } }
        </style>
    @endif
</head>
<body class="errbody">
    <a class="skip" href="#main">Skip to content</a>

    <header class="topbar">
        <div class="topbar-in">
            <a class="wordmark" href="/" aria-label="Dot.Engage, home">
                <x-logo />
            </a>
            <div class="topbar-end">
                @auth
                    @if ($quiet)
                        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="btn btn-quiet btn-sm"><span>Sign out</span></button></form>
                    @else
                        <a class="btn btn-sm" href="{{ route('dashboard') }}"><span>Desk</span></a>
                    @endif
                @else
                    @if (Route::has('login'))
                        <a class="btn btn-quiet btn-sm" href="{{ route('login') }}"><span>Sign in</span></a>
                    @endif
                @endauth
            </div>
        </div>
    </header>

    <main id="main" class="frame frame-narrow errpage">
        <p class="errcode fig"><span class="sr-only">Error </span>{{ $code }}</p>
        <h1>@yield('title')</h1>
        <p class="lede">@yield('message')</p>

        <div class="page-actions">
            @yield('actions')
        </div>

        @yield('after')

        @if (! empty($discover))
            <section class="eco-block" aria-labelledby="eco-h">
                <div class="section-head"><h2 id="eco-h">While you are here, try the rest of the Dot Ecosystem <span class="n">{{ count($discover) }}</span></h2></div>
                <ul class="eco">
                    @foreach ($discover as $platform)
                        <li><a href="{{ $platform['url'] }}">{{ $platform['name'] }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <footer class="foot">
            <span>&copy; {{ date('Y') }} Dot.Engage</span>
            @if (Route::has('contact'))
                <a href="{{ route('contact') }}">Contact support</a>
            @endif
        </footer>
    </main>
</body>
</html>
