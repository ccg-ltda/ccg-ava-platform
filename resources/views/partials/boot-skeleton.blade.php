{{--
    First paint of a page inside the application shell: the SAME shell as the real one (top bar, side menu with the width the person left it at, same padding and column) and the shape of the
    page that is coming (header, tabs, filters, cards, table...), while the JavaScript and the page chunk load, so the screen is never blank and nothing jumps when the page mounts.
    Plain HTML + CSS (the `.sk` class of app.css); it is removed the moment the application mounts (resources/js/bootstrap.jsx) and, if the application never mounts, a timer replaces it
    with a message and a retry link. The shape comes from config/skeleton.php (the client uses the same map). The side menu starts collapsed or expanded like the real one: see the
    script at the end of this file (the choice stored by useSidebarCollapsed, or collapsed on a narrow window when nothing was chosen).
    The React twin of these blocks is resources/js/Components/Skeleton.jsx; the proportions come from measuring the real pages: change both together.
--}}
@php
    $variant = \App\Support\Skeleton::variantFor(request()->path());
    $headers = ['dashboard' => 'filter', 'reports' => 'filter', 'tabbed_table' => 'action', 'cards' => 'action'];
    $headerRight = $headers[$variant] ?? null;
    // A long page shows the document's scrollbar (15 px) and the real content is narrower by that much: reserve it so the columns do not shift when the page arrives.
    $scrollbar = in_array($variant, ['dashboard', 'reports', 'agenda', 'conversations'], true);
    $filterCard = '<div class="h-[86px] rounded-xl border border-line bg-card p-4 sm:p-5"><div class="grid h-full gap-3 sm:grid-cols-2 lg:grid-cols-4"><div class="sk h-full"></div><div class="sk h-full"></div><div class="sk hidden h-full sm:block"></div><div class="sk hidden h-full sm:block"></div></div></div>';
    $tableCard = fn (int $rows) => '<div class="space-y-3 rounded-xl border border-line bg-card p-4 sm:p-5"><div class="sk h-9"></div>'.str_repeat('<div class="sk h-9"></div>', $rows).'</div>';
    $tabs = fn (int $count, string $height = 'h-[45px]') => '<div class="flex '.$height.' items-center gap-6 overflow-hidden border-b border-line">'.str_repeat('<div class="sk h-5 w-28 shrink-0"></div>', $count).'</div>';
@endphp
<div id="boot-skeleton" role="status" aria-live="polite" class="fixed inset-0 z-[60] flex flex-col {{ $scrollbar ? 'overflow-y-scroll' : 'overflow-hidden' }} bg-canvas font-sans text-ink">
    <span class="sr-only">Cargando…</span>
    <div class="brand-surface topbar-surface flex h-16 shrink-0 items-center gap-3 px-4 text-white shadow-card sm:px-6">
        <div class="sk size-10 rounded-full bg-white/25 md:hidden"></div>
        <div class="sk h-4 w-32 bg-white/30"></div>
        <div class="flex-1"></div>
        <div class="sk size-8 rounded-full bg-white/25"></div>
        <div class="sk hidden h-8 w-28 rounded-full bg-white/25 sm:block"></div>
    </div>
    <div class="flex min-h-0 flex-1">
        <div data-boot-side data-collapsed="false" class="group/bside brand-surface sidebar-surface hidden h-[calc(100dvh-4rem)] w-64 shrink-0 flex-col md:flex data-[collapsed=true]:w-16">
            <div class="flex h-16 shrink-0 items-center gap-3 px-4 group-data-[collapsed=true]/bside:justify-center group-data-[collapsed=true]/bside:px-0">
                <div class="sk size-9 shrink-0 rounded-xl bg-white/25"></div>
                <div class="sk h-4 w-28 bg-white/25 group-data-[collapsed=true]/bside:hidden"></div>
            </div>
            <div class="flex flex-col gap-3 px-3 pt-8">
                @foreach (range(1, 10) as $i)
                    <div class="sk h-9 rounded-full bg-white/20"></div>
                @endforeach
            </div>
        </div>
        <main class="min-w-0 flex-1 overflow-hidden px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto w-full max-w-screen-2xl space-y-6">
                @if ($variant === 'conversations')
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div class="min-w-0"><div class="sk h-10 w-64 max-w-full"></div><div class="sk mt-1 h-5 w-96 max-w-full"></div></div>
                            <div class="sk h-[34px] w-72 max-w-full rounded-md"></div>
                        </div>
                        <div class="sk h-4 w-24"></div>
                    </div>
                    <div class="sk h-16 rounded-xl"></div>
                    <div class="space-y-3">
                        <div class="flex h-[30px] gap-2 overflow-hidden">
                            @foreach (range(1, 5) as $i)
                                <div class="sk h-full w-24 shrink-0 rounded-full"></div>
                            @endforeach
                        </div>
                        <div class="grid h-[calc(100dvh-14rem)] min-h-[16rem] gap-0 overflow-hidden rounded-xl border border-line bg-card lg:h-[636px] lg:grid-cols-[22rem_1fr]">
                            <div class="space-y-4 p-4">
                                <div class="sk h-10"></div>
                                @foreach (range(1, 5) as $i)
                                    <div class="flex items-center gap-3"><div class="sk size-10 shrink-0 rounded-full"></div><div class="flex-1 space-y-2"><div class="sk h-4 w-2/3"></div><div class="sk h-3 w-full"></div></div></div>
                                @endforeach
                            </div>
                            <div class="hidden border-l border-line p-4 lg:block"><div class="sk ml-auto h-12 w-1/2 rounded-2xl"></div><div class="sk mt-4 h-12 w-2/5 rounded-2xl"></div></div>
                        </div>
                    </div>
                @else
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="min-w-0"><div class="sk h-9 w-56 max-w-full"></div><div class="sk mt-1 h-5 w-96 max-w-full"></div></div>
                        @if ($headerRight === 'action')
                            <div class="sk h-[34px] w-44 rounded-md"></div>
                        @elseif ($headerRight === 'filter')
                            <div class="sk h-[38px] w-52 max-w-full rounded-lg"></div>
                        @endif
                    </div>
                    @switch($variant)
                        @case('dashboard')
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach (range(1, 6) as $i)
                                    <div class="sk h-[124px] rounded-xl"></div>
                                @endforeach
                            </div>
                            <div class="grid gap-6 lg:grid-cols-3">
                                <div class="sk h-[434px] rounded-xl lg:col-span-2"></div>
                                <div class="sk h-[434px] rounded-xl"></div>
                            </div>
                            <div class="grid gap-6 lg:grid-cols-3">
                                @foreach (range(1, 3) as $i)
                                    <div class="sk h-[334px] rounded-xl"></div>
                                @endforeach
                            </div>
                            @break
                        @case('reports')
                            {!! $tabs(6, 'h-[60px]') !!}
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach (range(1, 4) as $i)
                                    <div class="sk h-28 rounded-xl"></div>
                                @endforeach
                            </div>
                            <div class="grid gap-6 lg:grid-cols-2">
                                <div class="sk h-72 rounded-xl"></div>
                                <div class="sk h-72 rounded-xl"></div>
                            </div>
                            @break
                        @case('tabbed_table')
                            {!! $tabs(2) !!}
                            {!! $filterCard !!}
                            {!! $tableCard(6) !!}
                            @break
                        @case('orders')
                            {!! $tabs(2, 'h-[46px]') !!}
                            <div class="sk h-4 w-2/3"></div>
                            {!! $filterCard !!}
                            {!! $tableCard(5) !!}
                            @break
                        @case('agenda')
                            {!! $tabs(4) !!}
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="sk h-10 w-24 rounded-md"></div>
                                <div class="sk h-10 w-48 rounded-md"></div>
                                <div class="flex-1"></div>
                                <div class="sk h-10 w-56 rounded-md"></div>
                            </div>
                            <div class="sk h-[860px] rounded-xl"></div>
                            @break
                        @case('cards')
                            {!! $tabs(4) !!}
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach (range(1, 9) as $i)
                                    <div class="sk h-[165px] rounded-xl"></div>
                                @endforeach
                            </div>
                            @break
                        @case('form')
                            @foreach (range(1, 2) as $i)
                                <div class="space-y-4 rounded-xl border border-line bg-card p-5">
                                    <div class="sk h-5 w-48"></div>
                                    <div class="grid gap-4 sm:grid-cols-2"><div class="sk h-10"></div><div class="sk h-10"></div><div class="sk h-10"></div><div class="sk h-10"></div></div>
                                </div>
                            @endforeach
                            @break
                        @default
                            <div class="space-y-4 rounded-xl border border-line bg-card p-4 sm:p-5">
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><div class="sk h-10"></div><div class="sk h-10"></div><div class="sk h-10"></div><div class="sk h-10"></div></div>
                                @foreach (range(1, 6) as $i)
                                    <div class="sk h-9"></div>
                                @endforeach
                            </div>
                    @endswitch
                @endif
            </div>
        </main>
    </div>
</div>
{{-- The side menu of the real shell is collapsed when the person chose so (localStorage `ava.sidebar.collapsed`), or, if they never chose, on a narrow window (below 1024 px). Same rule as useSidebarCollapsed. --}}
<script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
    try {
        var stored = localStorage.getItem('ava.sidebar.collapsed');
        var collapsed = stored === null ? window.matchMedia('(max-width: 1023px)').matches : stored === '1';
        var side = document.querySelector('[data-boot-side]');
        if (side) side.setAttribute('data-collapsed', collapsed ? 'true' : 'false');
    } catch (e) {}
</script>
