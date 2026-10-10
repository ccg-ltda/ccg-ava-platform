import { Head, router } from '@inertiajs/react';
import { Activity, FileDown, History, Pencil, Plus, SearchX, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SavedFilters from '@/Components/SavedFilters';
import StatCard from '@/Components/StatCard';
import AppLayout from '@/Layouts/AppLayout';
import AuditDetailModal from './Partials/AuditDetailModal';
import AuditEventCard from './Partials/AuditEventCard';
import AuditFilters from './Partials/AuditFilters';

const FILTER_KEYS = ['search', 'from', 'to', 'user', 'workspace', 'resource', 'action'];
/** The filters that can be saved: everything except the Workspace, which is a view choice and not a search criterion. */
const SAVED_KEYS = FILTER_KEYS.filter((key) => key !== 'workspace');

/** Query of the filters in use (empty ones left out), shared by the page and the PDF link. */
const queryOf = (filters) => Object.fromEntries(FILTER_KEYS.filter((key) => filters[key] !== null && filters[key] !== '').map((key) => [key, filters[key]]));

/**
 * Auditoría: the change history of the Workspace (who, what, on which record, when, from which IP). The server
 * filters, sorts and pages; this page only asks and shows. The PDF uses the filters currently applied.
 */
export default function Index({ events, summary, filters, perPageOptions, options, scope, savedFilters }) {
    const [selected, setSelected] = useState(null);
    const [loading, setLoading] = useState(false);
    const active = Object.keys(queryOf(filters)).length > 0;

    const visit = (params) =>
        router.get(route('audit.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    const criteria = Object.fromEntries(Object.entries(queryOf(filters)).filter(([key]) => SAVED_KEYS.includes(key)).map(([key, value]) => [key, String(value)]));

    // A saved criteria is applied only if everything it names still exists here (a user of this Workspace, a module, an action).
    const resolve = (saved) => {
        const known = { user: options.users, resource: options.resources, action: options.actions };
        const missing = Object.keys(known).find((key) => saved[key] !== undefined && !known[key].some((option) => String(option.value) === String(saved[key])));

        if (missing) return { error: `Este filtro ya no se puede aplicar: ${{ user: 'el usuario', resource: 'el módulo', action: 'la acción' }[missing]} que guarda ya no está disponible. Elimínalo o guarda uno nuevo.` };
        if (saved.from && saved.to && saved.from > saved.to) return { error: 'Este filtro tiene un período inválido (la fecha inicial es posterior a la final).' };

        return { criteria: saved };
    };

    // Applying a saved filter replaces every criterion; the Workspace being viewed is kept.
    const apply = (saved) => visit({ ...(filters.workspace ? { workspace: filters.workspace } : {}), ...saved, per_page: filters.perPage });

    // A change of filter or page size starts again at page 1.
    const change = (partial) => visit({ ...queryOf(filters), per_page: filters.perPage, ...partial });

    return (
        <>
            <Head title="Auditoría" />

            <PageHeader title="Auditoría" description="Quién creó, modificó o eliminó información y configuración, qué cambió y cuándo.">
                <a
                    href={route('audit.export', queryOf(filters))}
                    className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase shadow-sm transition duration-150 hover:bg-primary/90 focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:outline-none"
                >
                    <FileDown className="size-4" aria-hidden="true" />
                    Exportar PDF
                </a>
            </PageHeader>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Eventos" value={summary.total} hint={active ? 'Con los filtros aplicados' : 'Registrados en total'} icon={Activity} tone="violet" />
                <StatCard label="Creados" value={summary.created} hint="Registros nuevos" icon={Plus} tone="green" delay={60} />
                <StatCard label="Modificados" value={summary.updated} hint="Cambios en datos o configuración" icon={Pencil} tone="amber" delay={120} />
                <StatCard label="Eliminados" value={summary.deleted} hint="Registros o accesos quitados" icon={Trash2} tone="red" delay={180} />
            </div>

            <AuditFilters filters={filters} options={options} scope={scope} active={active} saved={<SavedFilters scope="audit" saved={savedFilters} criteria={criteria} resolve={resolve} onApply={apply} />} onChange={(partial) => change(partial)} onClear={() => visit({ per_page: filters.perPage })} />

            <section aria-label="Eventos de auditoría" aria-busy={loading} className={loading ? 'opacity-60 transition-opacity' : 'transition-opacity'}>
                {events.data.length === 0 ? (
                    <EmptyState
                        icon={active ? SearchX : History}
                        title={active ? 'Ningún evento coincide con los filtros' : 'Todavía no hay eventos'}
                        description={active ? 'Prueba con otro período, usuario o módulo, o limpia los filtros.' : 'Cuando alguien cree, modifique o elimine información administrativa aparecerá aquí.'}
                    />
                ) : (
                    <Card className="overflow-hidden">
                        <ul className="space-y-3 p-3 sm:p-4">
                            {events.data.map((event, index) => (
                                <AuditEventCard key={event.id} event={event} delay={index * 20} onOpen={() => setSelected(event)} />
                            ))}
                        </ul>
                        <Pagination
                            meta={events.meta}
                            noun="eventos"
                            perPage={events.meta.per_page}
                            perPageOptions={perPageOptions}
                            onPage={(page) => change({ page })}
                            onPerPage={(size) => change({ per_page: size })}
                        />
                    </Card>
                )}
            </section>

            <AuditDetailModal event={selected} onClose={() => setSelected(null)} />
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
