<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('branding.name') }}</title>
        <meta name="brand" content="{{ config('branding.name') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.favicon')
        @if (! empty($cspNonce))
            {{-- Inertia puts its progress-bar <style> on the page with this nonce (createInertiaApp `nonce`), so the style-src of the CSP needs no 'unsafe-inline'. --}}
            <meta name="csp-nonce" content="{{ $cspNonce }}">
        @endif

        <!-- Theme before first paint (light by default, choice persisted by the ThemeToggle) -->
        <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
            try { document.documentElement.className = localStorage.getItem('ccg-theme') === 'dark' ? 'dark' : 'light'; } catch (e) { document.documentElement.className = 'light'; }
        </script>

        {{-- Fonts: Inter is bundled with the application CSS (resources/css/app.css); no font provider is contacted. --}}

        @php($shell = \App\Support\Skeleton::hasShell($page['component'] ?? null))
        @if ($shell)
            {{-- The mode of the last session inside the application, before the first paint: the skeleton of a dark Workspace must not flash white (useWorkspaceTheme keeps it up to date). --}}
            <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
                try { if (localStorage.getItem('ava.app-theme') === 'dark') document.documentElement.dataset.appTheme = 'dark'; } catch (e) {}
            </script>
        @endif

        <!-- Scripts -->
        @routes(null, $cspNonce ?? null)
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
        @if ($shell)
            @include('partials.boot-skeleton')
            {{-- If the application never mounts (a script failed to load), the skeleton must not stay forever: after a while it says so and offers a retry. --}}
            <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
                setTimeout(function () {
                    var boot = document.getElementById('boot-skeleton');
                    if (!boot) return;
                    var box = document.createElement('div');
                    box.setAttribute('role', 'alert');
                    box.className = 'm-auto max-w-sm space-y-3 rounded-xl border border-line bg-card p-6 text-center text-sm text-ink';
                    var text = document.createElement('p');
                    text.textContent = 'No se pudo cargar la página. Revisa tu conexión e inténtalo de nuevo.';
                    var retry = document.createElement('button');
                    retry.type = 'button';
                    retry.textContent = 'Reintentar';
                    retry.className = 'rounded-md bg-primary px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase';
                    retry.onclick = function () { location.reload(); };
                    box.appendChild(text);
                    box.appendChild(retry);
                    boot.textContent = '';
                    boot.appendChild(box);
                }, {{ (int) config('skeleton.give_up_ms') }});
            </script>
        @endif
    </body>
</html>
