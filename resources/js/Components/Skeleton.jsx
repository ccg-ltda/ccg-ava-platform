/**
 * Loading placeholders. They show the STRUCTURE of the page that is coming (header, tabs, filters, cards, table rows, charts, a chat) so the screen keeps its shape and nothing jumps
 * when the real content arrives; they are never a spinner or a few generic bars. The shell (top bar and side menu) stays on screen: only the content area is replaced.
 *
 * The proportions come from measuring the real pages (heading block 60 px, tab bar 45 px, filter card 86 px, dashboard cards 124 px, ...): keep them alike. The twin of these blocks for
 * the first paint of the HTML shell lives in resources/views/partials/boot-skeleton.blade.php: change both together. Colors come from the `.sk` class (a tint of the ink color: it reads
 * in light and dark mode) and the pulse stops with `prefers-reduced-motion`. Which variant a module uses is config/skeleton.php.
 */

/** One placeholder block. Size it with the classes (`h-4 w-40`). */
export function Block({ className = '' }) {
    return <div className={`sk ${className}`} aria-hidden="true" />;
}

/** Title (36 px) + description (20 px), and on the right the button (`action`) or the period selector (`filter`) the real header has. */
function Header({ action = false, filter = false }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                <Block className="h-9 w-56 max-w-full" />
                <Block className="mt-1 h-5 w-96 max-w-full" />
            </div>
            {action && <Block className="h-[34px] w-44 rounded-md" />}
            {filter && <Block className="h-[38px] w-52 max-w-full rounded-lg" />}
        </div>
    );
}

/** The bar of tabs under the header (45 px). */
function TabsBar({ count = 3, height = 'h-[45px]' }) {
    return (
        <div className={`flex ${height} items-center gap-6 overflow-hidden border-b border-line`}>
            {Array.from({ length: count }, (_, index) => (
                <Block key={index} className="h-5 w-28 shrink-0" />
            ))}
        </div>
    );
}

/** The filter card (86 px): a row of fields. */
function FilterCard() {
    return (
        <div className="h-[86px] rounded-xl border border-line bg-card p-4 sm:p-5">
            <div className="grid h-full gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {[1, 2, 3, 4].map((item) => (
                    <Block key={item} className={`h-full ${item > 2 ? 'hidden sm:block' : ''}`} />
                ))}
            </div>
        </div>
    );
}

/** A card with a heading row and `rows` list rows. */
function TableCard({ rows = 6 }) {
    return (
        <div className="space-y-3 rounded-xl border border-line bg-card p-4 sm:p-5">
            <Block className="h-9" />
            {Array.from({ length: rows }, (_, index) => (
                <Block key={index} className="h-9" />
            ))}
        </div>
    );
}

/** Dashboard: six indicator cards (124 px), a wide chart with its side card, three lists and two charts. */
export function DashboardSkeleton() {
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {[1, 2, 3, 4, 5, 6].map((item) => (
                    <Block key={item} className="h-[124px] rounded-xl" />
                ))}
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                <Block className="h-[434px] rounded-xl lg:col-span-2" />
                <Block className="h-[434px] rounded-xl" />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                {[1, 2, 3].map((item) => (
                    <Block key={item} className="h-[334px] rounded-xl" />
                ))}
            </div>
        </>
    );
}

/** Reportes: the tabs of the report, then indicators and charts. */
export function ReportsSkeleton() {
    return (
        <>
            <TabsBar count={6} height="h-[60px]" />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[1, 2, 3, 4].map((item) => (
                    <Block key={item} className="h-28 rounded-xl" />
                ))}
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                <Block className="h-72 rounded-xl" />
                <Block className="h-72 rounded-xl" />
            </div>
        </>
    );
}

/** Filters and a list: the rows of a table. `rows` is how many to draw. (Modules without tabs: Usuarios, Auditoría, Catálogo...) */
export function TableSkeleton({ rows = 6 }) {
    return (
        <div className="space-y-4 rounded-xl border border-line bg-card p-4 sm:p-5">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {[1, 2, 3, 4].map((item) => (
                    <Block key={item} className="h-10" />
                ))}
            </div>
            {Array.from({ length: rows }, (_, index) => (
                <Block key={index} className="h-9" />
            ))}
        </div>
    );
}

/** Clientes and Recordatorios: tabs, the filter card and the list. */
export function TabbedTableSkeleton() {
    return (
        <>
            <TabsBar count={2} />
            <FilterCard />
            <TableCard />
        </>
    );
}

/** Pedidos: tabs, a hint line, the filters and the list. */
export function OrdersSkeleton() {
    return (
        <>
            <TabsBar count={2} height="h-[46px]" />
            <Block className="h-4 w-2/3" />
            <FilterCard />
            <TableCard rows={5} />
        </>
    );
}

/** Agenda: the tabs, the toolbar of the calendar and the calendar itself. */
export function AgendaSkeleton() {
    return (
        <>
            <TabsBar count={4} />
            <div className="flex flex-wrap items-center gap-3">
                <Block className="h-10 w-24 rounded-md" />
                <Block className="h-10 w-48 rounded-md" />
                <div className="flex-1" />
                <Block className="h-10 w-56 rounded-md" />
            </div>
            <Block className="h-[860px] rounded-xl" />
        </>
    );
}

/** Integraciones: tabs and a grid of channel cards. */
export function CardsSkeleton() {
    return (
        <>
            <TabsBar count={4} />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((item) => (
                    <Block key={item} className="h-[165px] rounded-xl" />
                ))}
            </div>
        </>
    );
}

/** Sections of fields (Configuraciones, Perfil). */
export function FormSkeleton() {
    return (
        <>
            {[1, 2].map((section) => (
                <div key={section} className="space-y-4 rounded-xl border border-line bg-card p-5">
                    <Block className="h-5 w-48" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        {[1, 2, 3, 4].map((field) => (
                            <Block key={field} className="h-10" />
                        ))}
                    </div>
                </div>
            ))}
        </>
    );
}

/** Conversaciones has no standard header: its own title row, the assignment bar, the filter pills and the list next to the chat. */
function ConversationsPage() {
    return (
        <>
            <div className="space-y-3">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-0">
                        <Block className="h-10 w-64 max-w-full" />
                        <Block className="mt-1 h-5 w-96 max-w-full" />
                    </div>
                    <Block className="h-[34px] w-72 max-w-full rounded-md" />
                </div>
                <Block className="h-4 w-24" />
            </div>
            <Block className="h-16 rounded-xl" />
            <div className="space-y-3">
                <div className="flex h-[30px] gap-2 overflow-hidden">
                    {[1, 2, 3, 4, 5].map((item) => (
                        <Block key={item} className="h-full w-24 shrink-0 rounded-full" />
                    ))}
                </div>
                <div className="grid h-[calc(100dvh-14rem)] min-h-[16rem] gap-0 overflow-hidden rounded-xl border border-line bg-card lg:h-[636px] lg:grid-cols-[22rem_1fr]">
                    <div className="space-y-4 p-4">
                        <Block className="h-10" />
                        {[1, 2, 3, 4, 5].map((item) => (
                            <div key={item} className="flex items-center gap-3">
                                <Block className="size-10 shrink-0 rounded-full" />
                                <div className="flex-1 space-y-2">
                                    <Block className="h-4 w-2/3" />
                                    <Block className="h-3 w-full" />
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="hidden border-l border-line p-4 lg:block">
                        <Block className="ml-auto h-12 w-1/2 rounded-2xl" />
                        <Block className="mt-4 h-12 w-2/5 rounded-2xl" />
                    </div>
                </div>
            </div>
        </>
    );
}

// The header of each variant: whether it has a button (`action`) or a period selector (`filter`) on the right.
const VARIANTS = {
    dashboard: { Body: DashboardSkeleton, header: { filter: true } },
    reports: { Body: ReportsSkeleton, header: { filter: true } },
    table: { Body: TableSkeleton, header: {} },
    tabbed_table: { Body: TabbedTableSkeleton, header: { action: true } },
    orders: { Body: OrdersSkeleton, header: {} },
    agenda: { Body: AgendaSkeleton, header: {} },
    cards: { Body: CardsSkeleton, header: { action: true } },
    form: { Body: FormSkeleton, header: {} },
};

/** The content area of a page that is loading: its header and the shape of its module (`variant` comes from `skeletonFor`). */
export default function PageSkeleton({ variant = 'table' }) {
    const wrapper = 'space-y-6';

    if (variant === 'conversations') {
        return (
            <div role="status" aria-busy="true" aria-live="polite" className={wrapper}>
                <span className="sr-only">Cargando…</span>
                <ConversationsPage />
            </div>
        );
    }

    const { Body, header } = VARIANTS[variant] ?? VARIANTS.table;

    return (
        <div role="status" aria-busy="true" aria-live="polite" className={wrapper}>
            <span className="sr-only">Cargando…</span>
            <Header {...header} />
            <Body />
        </div>
    );
}
