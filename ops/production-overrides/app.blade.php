@php
use Inovector\Mixpost\Mixpost;
use Inovector\Mixpost\Util;
@endphp
    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ Mixpost::getLocaleDirection() }}" class="scroll-smooth overflow-x-hidden">
<head>
    <title data-inertia>{{ config('app.name') }}</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="default_locale" content="{{ Util::config('default_locale') }}">
    @include('mixpost::partial.head')
    @foreach(Mixpost::getStyles() as $styleUrl)
        <link rel="stylesheet" href="{{ $styleUrl }}">
    @endforeach
    @if($bladePathScripts = Mixpost::getBladePathHeadScripts())
        @include($bladePathScripts)
    @endif
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-8SEC67R345"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}

        gtag('js', new Date());
        gtag('config', 'G-8SEC67R345', {send_page_view: false});

        const mixpostTrackPageView = (url) => {
            const pageUrl = new URL(url, window.location.origin);

            gtag('event', 'page_view', {
                page_location: pageUrl.href,
                page_path: `${pageUrl.pathname}${pageUrl.search}`,
                page_title: document.title,
            });
        };

        mixpostTrackPageView(window.location.href);

        document.addEventListener('inertia:navigate', (event) => {
            if (event.detail?.page?.url) {
                mixpostTrackPageView(event.detail.page.url);
            }
        });
    </script>
    @routes
    @inertiaHead
</head>
{{-- The app is a shell the height of the viewport and every list scrolls inside it, so the document
     itself never scrolls. Tooltips, dropdowns and the editor's bubble menu are appended here and
     positioned absolutely, and they keep following their anchor after it has scrolled out of its
     column — which used to leave one sitting far below the fold, giving the window a scrollbar and
     a screenful of empty page under the app. Body owns them now and clips what leaves the shell:
     `clip` rather than `hidden`, so it never becomes a scroller of its own. --}}
<body class="relative overflow-clip font-sans text-fg-neutral-primary">
@if($bladePathScripts = Mixpost::getBladePathBodyScripts())
    @include($bladePathScripts)
@endif

{{-- Packages extending Mixpost. Module scripts, so a package can split its screens into chunks it
     imports on demand rather than shipping all of them on every page of this app; they are deferred
     by definition, running after Mixpost's own bundle and before DOMContentLoaded — the window in
     which they register their pages and components. A package still shipping a plain script is
     unaffected: a script with no imports or exports is a valid module. --}}
@foreach(Mixpost::getScripts() as $scriptUrl)
    <script type="module" src="{{ $scriptUrl }}"></script>
@endforeach
@inertia

{{-- Mixpost's own bundle and every extension script are deferred, so they have all run by the time
     DOMContentLoaded fires. Starting here — rather than at parse time — is what lets a package
     register its Inertia pages before the app resolves the one it was asked to render. --}}
<script>
    (function () {
        function start() {
            // The layout ships with the PHP package but the bundle is a build artifact, so the two
            // can be a version apart right after an upgrade. An older bundle boots itself.
            if (window.Mixpost && typeof window.Mixpost.start === 'function') {
                window.Mixpost.start()
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start)
        } else {
            start()
        }
    })()
</script>
</body>
</html>
