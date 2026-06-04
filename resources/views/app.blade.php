<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth overflow-x-hidden">
<head>
    <title inertia>Mixpost{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('/vendor/mixpost/favicon.ico') }}">
    @if(config('mixpost.google_analytics_id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('mixpost.google_analytics_id') }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ config('mixpost.google_analytics_id') }}');
        </script>
    @endif
    @routes
    {{ mixpostAssets() }}
    @inertiaHead
</head>
<body class="font-sans">
@inertia
</body>
</html>
