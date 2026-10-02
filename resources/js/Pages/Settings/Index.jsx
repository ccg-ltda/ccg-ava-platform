import { Head, usePage } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';

/** Configuraciones: base page, ready to be filled. */
export default function Index() {
    const { workspace } = usePage().props;
    const rows = [
        ['Organization', workspace?.organization],
        ['Workspace', workspace?.name],
        ['Workspace code', workspace?.code],
    ];

    return (
        <>
            <Head title="Configuraciones" />

            <PageHeader title="Configuraciones" description="Ajustes del Workspace activo." />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="p-6 lg:col-span-1">
                    <h2 className="text-sm font-bold uppercase tracking-wider text-ink">Workspace</h2>
                    <dl className="mt-4 space-y-3">
                        {rows.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">{label}</dt>
                                <dd className="mt-0.5 truncate text-sm font-semibold text-ink">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </Card>

                <Card delay={80} className="p-6 lg:col-span-2">
                    <h2 className="text-sm font-bold uppercase tracking-wider text-ink">Preferencias</h2>
                    <EmptyState
                        className="mt-4"
                        icon={SlidersHorizontal}
                        title="Aún no hay configuraciones"
                        description="Las opciones de configuración de Ava aparecerán en esta sección."
                    />
                </Card>
            </div>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
