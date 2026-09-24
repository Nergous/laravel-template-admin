{{--
    Root layout for the public part of the site. Meta tags come from the SEO
    settings (App\Support\SeoMeta); a page can override them with sections:

        @extends('public')
        @section('title', 'О компании')
        @section('description', 'Коротко о нас')
        @section('og_image', '/storage/media/cover.webp')
        @section('content') ... @endsection

    Pages add their own styles/scripts through @push('head') / @push('scripts').
    The layout also works as an Inertia root view: when $page is present it
    renders @inertiaHead/@inertia instead of the content section (see
    HandleInertiaRequests::rootView()). Any inline <script> needs
    nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" to run in production.
--}}
@php
    $seo = \App\Support\SeoMeta::for(
        request(),
        trim($__env->yieldContent('title')),
        trim($__env->yieldContent('description')),
        trim($__env->yieldContent('og_image')),
    );
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $seo['title'] }}</title>

    @if ($seo['description'] !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if ($seo['robots'])
        <meta name="robots" content="{{ $seo['robots'] }}">
    @endif
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <link rel="icon" href="{{ $seo['favicon'] ?: '/favicon.svg' }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    @if ($seo['description'] !== '')
        <meta property="og:description" content="{{ $seo['description'] }}">
    @endif
    @if ($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif

    @stack('head')
    @isset($page)
        @inertiaHead
    @endisset
</head>

<body>
    @isset($page)
        @inertia
    @else
        @yield('content')
    @endisset

    @stack('scripts')
</body>

</html>
