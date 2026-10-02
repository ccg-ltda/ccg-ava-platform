import { Head } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';

const SLOTS = [0, 1, 2];

/** Integraciones: empty cards reserved for future integrations. */
export default function Index() {
    return (
        <>
            <Head title="Integraciones" />

            <PageHeader title="Integraciones" description="Conecta Ava con otros servicios." />

            <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                {SLOTS.map((slot) => (
                    <Card key={slot} hover delay={slot * 60} className="p-6">
                        <div className="flex items-center justify-between">
                            <span className="grid size-10 place-items-center rounded-xl bg-canvas text-ink-muted">
                                <Plug className="size-5" aria-hidden="true" />
                            </span>
                            <Badge>Próximamente</Badge>
                        </div>
                        <EmptyState
                            className="mt-4 py-6"
                            title="Sin integración"
                            description="Este espacio está reservado para una integración futura."
                        />
                    </Card>
                ))}
            </div>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
