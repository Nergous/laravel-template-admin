<!DOCTYPE html>
{{-- data-accent: the accent color preset from the settings (accents.css). --}}
@inject('accentColor', 'App\Services\System\AccentColor')
@inject('settings', 'App\Services\System\Settings')
<html lang="ru" data-accent="{{ $accentColor->current()->value }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Title and favicon: from settings (general.app_name, media library), otherwise defaults.
         This template also renders the 500 page, so a dead database must not break it. --}}
    @php
        try {
            $favicon = $settings->value('general', 'favicon');
            $appName = $settings->value('general', 'app_name') ?: config('app.name');
        } catch (\Throwable) {
            $favicon = null;
            $appName = config('app.name');
        }
    @endphp
    <title inertia>{{ $appName }}</title>
    <link rel="icon" href="{{ $favicon ?: '/favicon.svg' }}">


    {{-- theme anti-flash — before CSS, with a nonce (CSP) --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        try {
            var t = localStorage.getItem('nergous-ui-vue-theme');
            if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
            // The S/M/L density switch is gone (M only): forget a previously chosen density.
            localStorage.removeItem('nergous-ui-vue-density');
            localStorage.removeItem('nergous-ui-density');
        } catch (e) {}
    </script>

    @vite(['resources/js/admin/app.ts'])
    @inertiaHead
</head>

<body class="nergous-ui-vue-reset">
    @inertia
</body>

</html>
