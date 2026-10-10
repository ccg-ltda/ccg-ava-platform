import { Link, router } from '@inertiajs/react';
import { ArrowRight, CircleCheck, History, MessagesSquare, Plug } from 'lucide-react';
import Badge from '@/Components/Badge';
import ChartCard from '@/Components/Charts/ChartCard';
import EmptyState from '@/Components/EmptyState';
import { attentionItems, integrationStates, quickLinkHints } from '@/config/dashboard';
import { handlingStates } from '@/config/conversations';
import { visibleNavigation } from '@/config/navigation';

const retry = () => router.reload({ preserveScroll: true });

/** What needs a person right now. Every row is a real count; at zero it says nothing is waiting instead of hiding the row. */
export function AttentionPanel({ section, loading }) {
    const rows = Object.entries(section.data ?? {});

    return (
        <ChartCard title="Requiere atención" description="Lo que está esperando a una persona." loading={loading} error={section.error} errorTitle="No se pudo cargar esta sección" onRetry={retry} height={140}>
            <ul className="space-y-3">
                {rows.map(([key, count]) => {
                    const item = attentionItems[key];
                    const waiting = count > 0;

                    return (
                        <li key={key}>
                            <Link href={route(item.route, item.params)} className="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 transition-colors hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                                <span className="flex min-w-0 items-center gap-2 text-sm text-ink">
                                    {waiting ? <span className="size-2.5 shrink-0 rounded-full bg-accent-amber" aria-hidden="true" /> : <CircleCheck className="size-4 shrink-0 text-accent-green" aria-hidden="true" />}
                                    <span>{item.label}</span>
                                </span>
                                <span className={`shrink-0 text-sm font-bold ${waiting ? 'text-accent-amber' : 'text-ink-muted'}`}>{waiting ? count.toLocaleString('es') : 'Sin pendientes'}</span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </ChartCard>
    );
}

/**
 * The connections of the Workspace and what Ava can say about each. «Verificada» is the result of the last real test: it
 * does not promise the connection works now, and the panel says so.
 */
export function IntegrationsPanel({ section, loading }) {
    const items = section.data ?? [];

    return (
        <ChartCard
            title="Integraciones"
            description="Estado según la última prueba."
            actions={
                <Link href={route('integrations.index')} className="text-xs font-semibold tracking-wider text-primary uppercase hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                    Ver todas
                </Link>
            }
            loading={loading}
            error={section.error}
            errorTitle="No se pudo cargar esta sección"
            onRetry={retry}
            height={140}
        >
            {items.length === 0 ? (
                <EmptyState icon={Plug} title="Sin integraciones" description="Cuando conectes WhatsApp, el canal web o n8n aparecerán aquí." />
            ) : (
                <>
                    <ul className="space-y-2.5">
                        {items.map((item) => {
                            const state = integrationStates[item.state];

                            return (
                                <li key={item.id} className="flex items-center justify-between gap-3 text-sm">
                                    <span className="min-w-0">
                                        <span className="block truncate font-semibold text-ink">{item.name}</span>
                                        <span className="block truncate text-xs text-ink-muted">
                                            {item.type}
                                            {item.testedAt && ` · probada ${item.testedAt}`}
                                        </span>
                                    </span>
                                    <Badge tone={state.tone} className="shrink-0">
                                        {state.label}
                                    </Badge>
                                </li>
                            );
                        })}
                    </ul>
                    <p className="mt-4 text-xs text-ink-muted">«Verificada» es el resultado de la última prueba real; no garantiza que la conexión funcione ahora.</p>
                </>
            )}
        </ChartCard>
    );
}

/** The latest conversations by activity (only for who may read conversations). */
export function RecentConversations({ section, loading, foreign }) {
    const items = section.data ?? [];

    return (
        <ChartCard
            title="Conversaciones recientes"
            description="Las últimas por actividad."
            actions={
                <Link href={route('conversations.index')} className="text-xs font-semibold tracking-wider text-primary uppercase hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                    Abrir bandeja
                </Link>
            }
            loading={loading}
            error={section.error}
            errorTitle="No se pudo cargar esta sección"
            onRetry={retry}
            height={160}
        >
            {items.length === 0 ? (
                <EmptyState icon={MessagesSquare} title="Todavía no hay conversaciones" description="Aparecerán aquí cuando los contactos escriban." />
            ) : (
                <ul className="divide-y divide-line">
                    {items.map((item) => {
                        const state = handlingStates[item.handling];

                        return (
                            <li key={item.id}>
                                <Link
                                    href={route('conversations.index', { c: item.id, ...(foreign ? { workspace: item.workspaceId } : {}) })}
                                    className="flex items-center justify-between gap-3 py-2.5 transition-colors hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-semibold text-ink">{item.contact}</span>
                                        <span className="block truncate text-xs text-ink-muted">
                                            {item.channel} · {item.assistant}
                                            {item.workspace && ` · ${item.workspace}`}
                                        </span>
                                    </span>
                                    <span className="flex shrink-0 flex-col items-end gap-1">
                                        <Badge tone={state.tone}>{state.short}</Badge>
                                        <span className="text-[11px] text-ink-muted">{item.lastAt}</span>
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </ChartCard>
    );
}

/** The latest administrative events (only for who may read the audit page). */
export function RecentActivity({ section, loading }) {
    const items = section.data ?? [];

    return (
        <ChartCard
            title="Actividad reciente"
            description="Los últimos cambios administrativos."
            actions={
                <Link href={route('audit.index')} className="text-xs font-semibold tracking-wider text-primary uppercase hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                    Ver auditoría
                </Link>
            }
            loading={loading}
            error={section.error}
            errorTitle="No se pudo cargar esta sección"
            onRetry={retry}
            height={160}
        >
            {items.length === 0 ? (
                <EmptyState icon={History} title="Sin actividad todavía" description="Cuando alguien cree, modifique o elimine información administrativa aparecerá aquí." />
            ) : (
                <ul className="divide-y divide-line">
                    {items.map((item) => (
                        <li key={item.id} className="py-2.5">
                            <p className="text-sm text-ink">{item.description}</p>
                            <p className="mt-0.5 text-xs text-ink-muted">
                                {item.user} · {item.date} {item.time}
                                {item.workspace && ` · ${item.workspace}`}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </ChartCard>
    );
}

/** Links to the modules the user may open: the sidebar's own definition, so what the menu hides is not offered here either. */
export function QuickLinks({ permissions }) {
    const items = visibleNavigation(permissions).flatMap((section) => section.items).filter((item) => item.route !== 'dashboard');

    if (items.length === 0) return null;

    return (
        <section aria-label="Accesos rápidos">
            <h2 className="mb-3 text-sm font-bold tracking-wider text-ink uppercase">Accesos rápidos</h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {items.map((item) => (
                    <Link
                        key={item.route}
                        href={route(item.route)}
                        className="group flex items-center gap-3 rounded-card border border-line bg-card p-4 shadow-card transition duration-200 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-primary motion-safe:hover:-translate-y-0.5"
                    >
                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                            <item.icon className="size-5" aria-hidden="true" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-ink">{item.label}</span>
                            <span className="block truncate text-xs text-ink-muted">{quickLinkHints[item.route]}</span>
                        </span>
                        <ArrowRight className="size-4 shrink-0 text-ink-muted transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                    </Link>
                ))}
            </div>
        </section>
    );
}
