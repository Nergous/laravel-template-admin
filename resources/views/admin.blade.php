<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name') }}</title>

    {{-- Favicon: from settings (media library), otherwise a static file --}}
    @php($favicon = \App\Models\Setting::value('general', 'favicon'))
    <link rel="icon" href="{{ $favicon ?: '/favicon.ico' }}">


    {{-- theme and density anti-flash — before CSS, with a nonce (CSP) --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        try {
            var t = localStorage.getItem('nergouscit-theme');
            var d = localStorage.getItem('nergouscit-density');
            if (t) document.documentElement.setAttribute('data-theme', t);
            if (d) document.documentElement.setAttribute('data-density', d);
        } catch (e) {}
    </script>

    @vite(['resources/js/admin/app.js'])
    @inertiaHead
</head>

<body class="nergouscit-reset">
    @inertia
</body>

</html>
