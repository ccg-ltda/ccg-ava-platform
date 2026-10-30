<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('branding.name') }}</title>
        <meta name="brand" content="{{ config('branding.name') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if (! empty($cspNonce))
            {{-- Inertia puts its progress-bar <style> on the page with this nonce (createInertiaApp `nonce`), so the style-src of the CSP needs no 'unsafe-inline'. --}}
            <meta name="csp-nonce" content="{{ $cspNonce }}">
        @endif

        <!-- Theme before first paint (light by default, choice persisted by the ThemeToggle) -->
        <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
            try { document.documentElement.className = localStorage.getItem('ccg-theme') === 'dark' ? 'dark' : 'light'; } catch (e) { document.documentElement.className = 'light'; }
        </script>

        {{-- Fonts: Inter is bundled with the application CSS (resources/css/app.css); no font provider is contacted. --}}

        <!-- Scripts -->
        @routes(null, $cspNonce ?? null)
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
