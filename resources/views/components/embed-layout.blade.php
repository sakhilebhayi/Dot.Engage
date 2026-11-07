@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Dot.Engage' }} · Dot.Engage</title>
    @vite(['resources/css/engage.css', 'resources/js/engage.js'])
    @stack('head')
</head>
<body class="embed">
    <x-icons />
    <main id="main" class="embed-main">
        @if (session('status'))<div class="flash is-ok" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </main>
    @stack('scripts')
</body>
</html>
